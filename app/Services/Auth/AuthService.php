<?php

namespace App\Services\Auth;

use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Repositories\UserRepository;
use App\Repositories\EmpleadoRepository;
use App\Services\Auth\BackoffService;
use App\Services\Auth\TurnstileService;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthService
{
    public function __construct(
        private UserRepository $userRepository,
        private EmpleadoRepository $empleadoRepository,
        private BackoffService $backoffService,
        private TurnstileService $turnstileService
    ) {}

    public function register(RegisterDTO $dto): array
    {
        DB::beginTransaction();

        try {
            $user = $this->userRepository->create([
                'nombre' => $dto->nombre,
                'apellido' => $dto->apellido,
                'email' => $dto->email,
            ]);

            $empleado = $this->empleadoRepository->createForUser(
                (array) $dto, // Truco rápido para pasar los datos
                $user->id
            );

            DB::commit();

            $rol = $this->empleadoRepository->getRol($empleado);
            $permissions = $this->empleadoRepository->getPermissions($rol);
            $token = $this->userRepository->createToken($user, 'auth_token', [$rol->nombre]);

            return [
                'user' => $user,
                'empleado' => $empleado,
                'rol' => $rol->nombre,
                'permisos' => $permissions,
                'token' => $token,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function login(LoginDTO $dto): array
    {
        if (! $this->turnstileService->verify($dto->captcha_token, $dto->ip)) {
            Log::warning('Turnstile verification failed', [
                'email_hash' => hash('sha256', strtolower(trim($dto->email))),
                'ip' => $dto->ip,
            ]);
            throw new \Exception('Verificación de seguridad fallida. Recarga la página e intenta de nuevo.', 422);
        }

        $waitSeconds = $this->backoffService->getRemainingWaitSeconds($dto->email);
        if ($waitSeconds > 0) {
            throw new \Exception(
                'Cuenta temporalmente bloqueada. Intenta de nuevo en '.ceil($waitSeconds / 60).' minuto(s).',
                429
            );
        }

        $user = $this->userRepository->findByEmail($dto->email);

        if (!$user || !Hash::check($dto->password, $user->password)) {
            $this->backoffService->registerFailedAttempt($dto->email, $dto->ip, !$user ? 'user_not_found' : 'wrong_password');
            throw new \Exception('El email o la contraseña son incorrectos.', 401);
        }

        $this->backoffService->reset($dto->email);

        $empleado = $this->empleadoRepository->getByUser($user);
        if (!$empleado || !$empleado->rol) {
            throw new \Exception('El usuario no tiene un rol asignado', 403);
        }

        $empleado = $this->empleadoRepository->loadRelations($empleado);
        $rol = $this->empleadoRepository->getRol($empleado);
        $permissions = $this->empleadoRepository->getPermissions($rol);

        $this->userRepository->deleteTokens($user);
        $token = $this->userRepository->createToken($user, 'auth_token', [$rol->nombre]);

        return [
            'user' => $user,
            'empleado' => $empleado,
            'rol' => $rol->nombre,
            'permisos' => $permissions,
            'token' => $token,
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }
}