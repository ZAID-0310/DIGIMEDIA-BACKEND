<?php

namespace Tests\Feature;

use App\DTOs\WhatsAppCampaign\CreateCampaignDTO;
use App\Jobs\SendWhatsAppCampaignJob;
use App\Models\CampaniaWhatsApp;
use App\Models\User;
use App\Models\WatModal;
use App\Models\modalservicios;
use App\Services\WhatsAppCampaignService;
use Cloudinary\Api\ApiResponse;
use Cloudinary\Api\Upload\UploadApi;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Mockery;
use Tests\TestCase;

class WhatsAppCampaignSelectionTest extends TestCase
{
    private User $user;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
        });

        Schema::create('servicios', function (Blueprint $table) {
            $table->id('id_servicio');
            $table->string('nombre')->nullable();
            $table->string('descripcion')->nullable();
        });

        Schema::create('campanias_whatsapp', function (Blueprint $table) {
            $table->id('id_campania');
            $table->unsignedBigInteger('id_servicio');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('parrafo');
            $table->string('imagen_url', 500);
            $table->string('estado');
            $table->integer('total_destinatarios')->default(0);
            $table->integer('envios_exitosos')->default(0);
            $table->integer('envios_fallidos')->default(0);
            $table->integer('envios_pendientes')->default(0);
            $table->integer('envios_hoy')->default(0);
            $table->date('fecha_ultimo_envio')->nullable();
            $table->timestamp('fecha_inicio')->nullable();
            $table->timestamp('fecha_fin')->nullable();
            $table->timestamps();

            $table->foreign('id_servicio')->references('id_servicio')->on('servicios');
            $table->foreign('user_id')->references('id')->on('users');
        });

        $contactsMigration = require database_path(
            'migrations/2025_03_13_001237_create_modalservicios_table.php'
        );
        $recipientsMigration = require database_path(
            'migrations/2026_11_06_010000_create_campania_destinatarios_table.php'
        );
        $persistedFlagMigration = require database_path(
            'migrations/2026_11_06_010001_add_destinatarios_persistidos_to_campanias_whatsapp_table.php'
        );
        $contactsMigration->up();
        $recipientsMigration->up();
        $persistedFlagMigration->up();

        Schema::create('modal_wats', function (Blueprint $table) {
            $table->id('id_modal_wat');
            $table->boolean('estado')->default(false);
            $table->string('error')->nullable();
            $table->unsignedBigInteger('id_modalservicio');
            $table->unsignedBigInteger('campania_id')->nullable();
            $table->unsignedTinyInteger('intentos')->default(1);
            $table->boolean('puede_reintentar')->default(true);
            $table->unsignedTinyInteger('number_message')->default(1);
            $table->date('fecha')->nullable();
        });

        DB::table('servicios')->insert([
            'id_servicio' => 1,
            'nombre' => 'Servicio ficticio',
            'descripcion' => 'Fixture',
        ]);
        DB::table('users')->insert([
            'id' => 1,
            'name' => 'Usuario de prueba',
            'email' => 'test@example.invalid',
            'password' => 'not-a-real-password',
        ]);
        $this->user = User::findOrFail(1);

        foreach ([
            ['Contacto ficticio 1', '900000001', 1],
            ['Contacto ficticio 2', '900000002', 1],
            ['Contacto ficticio 3', '900000003', 1],
            ['Contacto inactivo', '900000004', 0],
        ] as [$nombre, $telefono, $estado]) {
            modalservicios::create([
                'nombre' => $nombre,
                'telefono' => $telefono,
                'correo' => strtolower(str_replace(' ', '.', $nombre)) . '@example.invalid',
                'id_servicio' => 1,
                'estado' => $estado,
            ]);
        }
    }

    public function test_creation_persists_all_or_requested_number_of_eligible_contacts(): void
    {
        $uploadApi = Mockery::mock(UploadApi::class);
        $uploadApi->shouldReceive('upload')
            ->times(4)
            ->andReturn(new ApiResponse([
                'secure_url' => 'https://example.invalid/fixture.png',
            ], []));
        Cloudinary::shouldReceive('uploadApi')->times(4)->andReturn($uploadApi);

        $cases = [
            [null, 3],
            [1, 1],
            [2, 2],
            [3, 3],
        ];

        foreach ($cases as [$requestedCount, $expectedCount]) {
            $campaign = $this->createCampaign($requestedCount);
            $expectedIds = modalservicios::query()
                ->where('id_servicio', 1)
                ->where('estado', 1)
                ->orderBy('id_modalservicio')
                ->limit($expectedCount)
                ->pluck('id_modalservicio')
                ->all();

            $this->assertSame($expectedCount, $campaign->total_destinatarios);
            $this->assertSame($expectedCount, $campaign->envios_pendientes);
            $this->assertTrue($campaign->destinatarios_persistidos);
            $this->assertSame(
                $expectedIds,
                $campaign->destinatarios()
                    ->orderBy('modalservicios.id_modalservicio')
                    ->pluck('modalservicios.id_modalservicio')
                    ->all()
            );
        }
    }

    public function test_invalid_or_unavailable_recipient_counts_return_validation_errors(): void
    {
        foreach ([0, -1, 'no-numero'] as $requestedCount) {
            $response = $this->createCampaignResponse($requestedCount);
            $this->assertSame(422, $response->getStatusCode());
            $this->assertSame(0, CampaniaWhatsApp::count());
        }

        Cloudinary::shouldReceive('uploadApi')->never();
        $response = $this->createCampaignResponse(4);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, CampaniaWhatsApp::count());
    }

    public function test_create_endpoint_uses_the_validated_recipient_count(): void
    {
        $uploadApi = Mockery::mock(UploadApi::class);
        $uploadApi->shouldReceive('upload')
            ->once()
            ->andReturn(new ApiResponse([
                'secure_url' => 'https://example.invalid/fixture.png',
            ], []));
        Cloudinary::shouldReceive('uploadApi')->once()->andReturn($uploadApi);

        $response = $this->createCampaignResponse(2);
        $campaign = CampaniaWhatsApp::firstOrFail();

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(2, $response->getData(true)['data']['total_destinatarios']);
        $this->assertTrue($campaign->destinatarios_persistidos);
        $this->assertSame(2, $campaign->destinatarios()->count());
    }

    public function test_preview_create_and_start_queue_the_exact_persisted_selection(): void
    {
        Http::fake(['*' => Http::response(['connected' => true], 200)]);
        Queue::fake();

        $preview = app(WhatsAppCampaignService::class)->previewCampaign('p1');
        $this->assertSame(3, $preview['total_destinatarios']);

        $uploadApi = Mockery::mock(UploadApi::class);
        $uploadApi->shouldReceive('upload')
            ->once()
            ->andReturn(new ApiResponse([
                'secure_url' => 'https://example.invalid/fixture.png',
            ], []));
        Cloudinary::shouldReceive('uploadApi')->once()->andReturn($uploadApi);

        $createResponse = $this->createCampaignResponse(2);
        $this->assertSame(201, $createResponse->getStatusCode());

        $campaign = CampaniaWhatsApp::firstOrFail();
        $this->assertSame(2, $createResponse->getData(true)['data']['total_destinatarios']);
        $this->assertSame([1, 2], $campaign->destinatarios()
            ->orderBy('modalservicios.id_modalservicio')
            ->pluck('modalservicios.id_modalservicio')
            ->all());

        modalservicios::create([
            'nombre' => 'Contacto incorporado después de crear',
            'telefono' => '900000005',
            'correo' => 'posterior@example.invalid',
            'id_servicio' => 1,
            'estado' => 1,
        ]);

        app(WhatsAppCampaignService::class)->startCampaign($campaign->id_campania, $this->user);

        Queue::assertPushed(SendWhatsAppCampaignJob::class, function (SendWhatsAppCampaignJob $job): bool {
            return array_column($job->destinatarios, 'id_modalservicio') === [1, 2];
        });
    }

    public function test_requested_count_above_zero_available_contacts_is_a_validation_error(): void
    {
        modalservicios::query()->update(['estado' => 0]);
        Cloudinary::shouldReceive('uploadApi')->never();

        $response = $this->createCampaignResponse(1);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, CampaniaWhatsApp::count());
    }

    public function test_association_failure_rolls_back_campaign_and_persisted_flag(): void
    {
        DB::statement(
            "CREATE TRIGGER fail_campaign_recipient BEFORE INSERT ON campania_destinatarios
             WHEN NEW.id_modalservicio = 2
             BEGIN SELECT RAISE(FAIL, 'simulated association failure'); END"
        );

        $uploadApi = Mockery::mock(UploadApi::class);
        $uploadApi->shouldReceive('upload')
            ->once()
            ->andReturn(new ApiResponse([
                'secure_url' => 'https://example.invalid/fixture.png',
            ], []));
        Cloudinary::shouldReceive('uploadApi')->once()->andReturn($uploadApi);

        try {
            $this->createCampaign(2);
            $this->fail('Expected recipient association persistence to fail.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(0, CampaniaWhatsApp::count());
            $this->assertSame(0, DB::table('campania_destinatarios')->count());
        }
    }

    public function test_start_queues_only_persisted_eligible_recipients(): void
    {
        Http::fake(['*' => Http::response(['connected' => true], 200)]);
        Queue::fake();

        $selected = $this->createStoredCampaign(true, 'borrador', [1, 2]);
        modalservicios::create([
            'nombre' => 'Contacto agregado después',
            'telefono' => '900000005',
            'correo' => 'nuevo@example.invalid',
            'id_servicio' => 1,
            'estado' => 1,
        ]);

        app(WhatsAppCampaignService::class)->startCampaign($selected->id_campania, $this->user);

        Queue::assertPushed(SendWhatsAppCampaignJob::class, function (SendWhatsAppCampaignJob $job): bool {
            return array_column($job->destinatarios, 'id_modalservicio') === [1, 2];
        });
    }

    public function test_persisted_campaign_without_associations_fails_integrity_check(): void
    {
        Http::fake(['*' => Http::response(['connected' => true], 200)]);
        Queue::fake();
        $campaign = $this->createStoredCampaign(true, 'borrador', []);

        try {
            app(WhatsAppCampaignService::class)->startCampaign($campaign->id_campania, $this->user);
            $this->fail('Expected missing persisted recipients to stop campaign start.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Error de integridad', $exception->getMessage());
        }

        Queue::assertNothingPushed();
    }

    public function test_legacy_campaign_keeps_dynamic_recipient_selection(): void
    {
        Http::fake(['*' => Http::response(['connected' => true], 200)]);
        Queue::fake();
        $campaign = $this->createStoredCampaign(false, 'borrador', []);
        modalservicios::create([
            'nombre' => 'Contacto posterior legacy',
            'telefono' => '900000005',
            'correo' => 'legacy@example.invalid',
            'id_servicio' => 1,
            'estado' => 1,
        ]);

        app(WhatsAppCampaignService::class)->startCampaign($campaign->id_campania, $this->user);

        Queue::assertPushed(SendWhatsAppCampaignJob::class, function (SendWhatsAppCampaignJob $job): bool {
            return array_column($job->destinatarios, 'id_modalservicio') === [1, 2, 3, 5];
        });
    }

    public function test_start_excludes_deactivated_persisted_contacts_and_reconciles_counts(): void
    {
        Http::fake(['*' => Http::response(['connected' => true], 200)]);
        Queue::fake();
        $campaign = $this->createStoredCampaign(true, 'borrador', [1, 2]);
        modalservicios::where('id_modalservicio', 1)->update(['estado' => 0]);

        app(WhatsAppCampaignService::class)->startCampaign($campaign->id_campania, $this->user);
        $campaign->refresh();

        $this->assertSame(1, $campaign->total_destinatarios);
        $this->assertSame(1, $campaign->envios_pendientes);
        Queue::assertPushed(SendWhatsAppCampaignJob::class, function (SendWhatsAppCampaignJob $job): bool {
            return array_column($job->destinatarios, 'id_modalservicio') === [2];
        });
    }

    public function test_start_excludes_persisted_contact_whose_phone_becomes_empty(): void
    {
        Http::fake(['*' => Http::response(['connected' => true], 200)]);
        Queue::fake();
        $campaign = $this->createStoredCampaign(true, 'borrador', [1]);
        modalservicios::where('id_modalservicio', 1)->update(['telefono' => '']);

        try {
            app(WhatsAppCampaignService::class)->startCampaign($campaign->id_campania, $this->user);
            $this->fail('Expected campaign with no eligible contacts not to start.');
        } catch (\Exception $exception) {
            $this->assertSame('No hay destinatarios elegibles pendientes para esta campaña', $exception->getMessage());
        }

        $campaign->refresh();
        $this->assertSame(0, $campaign->total_destinatarios);
        $this->assertSame(0, $campaign->envios_pendientes);
        Queue::assertNothingPushed();
    }

    public function test_auto_resume_uses_only_unprocessed_persisted_eligible_contacts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00', 'America/Lima'));
        Queue::fake();
        $campaign = $this->createStoredCampaign(true, 'pausada_fuera_horario', [1, 2]);
        modalservicios::create([
            'nombre' => 'Contacto agregado después',
            'telefono' => '900000005',
            'correo' => 'nuevo@example.invalid',
            'id_servicio' => 1,
            'estado' => 1,
        ]);
        WatModal::create([
            'id_modalservicio' => 1,
            'campania_id' => $campaign->id_campania,
            'number_message' => 1,
            'estado' => true,
            'fecha' => '2026-11-06',
        ]);

        $this->artisan('campaign:auto-resume')->assertExitCode(0);

        Queue::assertPushed(SendWhatsAppCampaignJob::class, function (SendWhatsAppCampaignJob $job): bool {
            return array_column($job->destinatarios, 'id_modalservicio') === [2];
        });
    }

    public function test_auto_resume_does_not_fallback_when_persisted_associations_are_missing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00', 'America/Lima'));
        Queue::fake();
        $campaign = $this->createStoredCampaign(true, 'pausada_fuera_horario', []);
        $campaign->update(['envios_pendientes' => 1]);

        $this->artisan('campaign:auto-resume')->assertExitCode(0);

        Queue::assertNothingPushed();
        $campaign->refresh();
        $this->assertSame('pausada_fuera_horario', $campaign->estado);
    }

    private function createStoredCampaign(bool $persisted, string $status, array $recipientIds): CampaniaWhatsApp
    {
        $campaign = CampaniaWhatsApp::create([
            'id_servicio' => 1,
            'user_id' => $this->user->id,
            'parrafo' => 'Mensaje de campaña ficticio',
            'imagen_url' => 'https://example.invalid/fixture.png',
            'estado' => $status,
            'total_destinatarios' => count($recipientIds),
            'envios_pendientes' => count($recipientIds),
            'destinatarios_persistidos' => $persisted,
        ]);
        if ($recipientIds !== []) {
            $campaign->destinatarios()->attach($recipientIds);
        }

        return $campaign;
    }

    private function createCampaign(?int $recipientCount): CampaniaWhatsApp
    {
        $dto = new CreateCampaignDTO(
            service: 'p1',
            paragraph: 'Mensaje de prueba ficticio',
            image: UploadedFile::fake()->create('fixture.png', 1, 'image/png'),
            recipientCount: $recipientCount
        );

        return app(WhatsAppCampaignService::class)->createCampaign($dto, $this->user);
    }

    private function createCampaignResponse(mixed $recipientCount)
    {
        $input = [
            'service' => 'p1',
            'paragraph' => 'Mensaje de prueba ficticio',
        ];
        if ($recipientCount !== null) {
            $input['cantidad_destinatarios'] = $recipientCount;
        }

        $request = Request::create('/api/whatsapp/campaign/create', 'POST', $input, [], [
            'image' => UploadedFile::fake()->create('fixture.png', 1, 'image/png'),
        ]);
        $request->setUserResolver(fn () => $this->user);

        return app(\App\Http\Controllers\Api\WhatsAppCampaignController::class)
            ->createCampaign($request);
    }
}
