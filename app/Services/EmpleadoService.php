<?php

namespace App\Services;

use App\Repositories\EmpleadoRepository;
use App\DTOs\Empleado\CreateEmpleadoDTO;
use App\DTOs\Empleado\UpdateEmpleadoDTO;
use App\DTOs\Empleado\VerifyEmpleadoPasswordDTO;
use App\DTOs\Empleado\UpdateEmpleadoPasswordDTO;
use App\DTOs\Empleado\UpdateProfileImageDTO;
use App\Models\Empleado;
use App\Models\User;
use App\Mail\CredencialesEmpleadoMail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Cloudinary\Cloudinary;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class EmpleadoService
{
    public function __construct(
        private EmpleadoRepository $repository
    ) {}

    public function getEmpleadoById(int $id, User $currentUser): Empleado
    {
        $empleado = $this->repository->findById($id);

        if (!$empleado) {
            throw new ModelNotFoundException('Empleado no encontrado');
        }

        $empleado = $this->repository->loadRelations($empleado);

        // Verificar si el usuario autenticado es admin
        $currentEmpleado = Empleado::where('id_user', $currentUser->id)->first()
            ?: Empleado::where('id_empleado', $currentUser->id)->first();
            
        $isAdmin = $currentEmpleado && $currentEmpleado->rol && $currentEmpleado->rol->isAdmin();

        if (!$isAdmin && $empleado->dni) {
            $empleado->dni = $this->formatDni($empleado->dni, false);
        }

        return $empleado;
    }

    public function getEmpleadosPaginated(array $params, User $currentUser): array
    {
        $search = $params['search'] ?? '';
        $rol = $params['rol'] ?? 'all';
        $limit = $params['limit'] ?? 5;
        $sortBy = $params['sortBy'] ?? 'id_empleado';
        $sortOrder = $params['sortOrder'] ?? 'asc';

        $currentEmpleado = Empleado::where('id_user', $currentUser->id)->first()
            ?: Empleado::where('id_empleado', $currentUser->id)->first();
            
        $isAdmin = $currentEmpleado && $currentEmpleado->rol && $currentEmpleado->rol->isAdmin();

        $paginated = $this->repository->getAllPaginated($search, $rol, $sortBy, $sortOrder, $limit);

        $paginated->getCollection()->transform(function ($empleado) use ($isAdmin) {
            return [
                'id_empleado' => $empleado->id_empleado,
                'nombre' => $empleado->nombre,
                'apellido' => $empleado->apellido,
                'email' => $empleado->email,
                'dni' => $this->formatDni($empleado->dni ?? '', $isAdmin),
                'telefono' => $empleado->telefono,
                'rol' => $empleado->rol->nombre ?? 'Sin Rol',
                'id_rol' => $empleado->rol->id_rol ?? null,
                'subtipo_admin' => $empleado->subtipoAdmin
            ];
        });

        return [
            'data' => $paginated->items(),
            'total' => $paginated->total(),
            'page' => $paginated->currentPage()
        ];
    }

    public function createEmpleado(CreateEmpleadoDTO $dto): array
    {
        DB::beginTransaction();
        try {
            $password = $this->createPassword($dto->dni, $dto->nombre, $dto->apellido);

            $user = User::create([
                'name' => $dto->nombre . ' ' . $dto->apellido,
                'email' => $dto->email,
                'password' => Hash::make($password),
            ]);

            $empleado = $this->repository->createForUser($dto->toArray(), $user->id);

            DB::commit();

            try {
                Mail::to($user->email)->send(new CredencialesEmpleadoMail($user, $password));
            } catch (\Exception $e) {
                Log::error('Error al enviar correo de credenciales a empleado', [
                    'email' => $user->email,
                    'error' => $e->getMessage()
                ]);
            }

            return [
                'user' => $user,
                'empleado' => $empleado
            ];
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function updateEmpleado(int $id, UpdateEmpleadoDTO $dto, User $currentUser): Empleado
    {
        $empleado = $this->repository->findById($id);

        if (!$empleado) {
            throw new ModelNotFoundException('Empleado no encontrado');
        }

        $this->checkPermission($empleado, $currentUser);

        $user = User::find($empleado->id_user);

        if ($user) {
            if (isset($dto->data['email']) && $dto->data['email'] != $empleado->email) {
                $user->email = $dto->data['email'];
            }

            if (
                (isset($dto->data['nombre']) && $dto->data['nombre'] != $empleado->nombre) ||
                (isset($dto->data['apellido']) && $dto->data['apellido'] != $empleado->apellido)
            ) {
                $nombre = isset($dto->data['nombre']) ? $dto->data['nombre'] : $empleado->nombre;
                $apellido = isset($dto->data['apellido']) ? $dto->data['apellido'] : $empleado->apellido;
                $user->name = $nombre . ' ' . $apellido;
            }

            $user->save();
        }

        $this->repository->update($empleado, $dto->data);

        if (isset($dto->data['id_rol']) && $dto->data['id_rol'] != 1) {
            $empleado->id_subtipo_admin = null;
            $empleado->save();
        }

        return $empleado->fresh();
    }

    public function generateUploadSignature(int $id, array $params, User $currentUser): string
    {
        $empleado = $this->repository->findById($id);
        if (!$empleado) {
            throw new ModelNotFoundException('Empleado no encontrado');
        }

        $this->checkPermission($empleado, $currentUser);

        $paramsToSign = $params;

        // El backend impone los parámetros críticos
        $paramsToSign['folder'] = "empleados/perfiles/{$id}";
        $paramsToSign['public_id'] = 'profile';
        $paramsToSign['overwrite'] = 'true';

        // Parámetros excluidos
        $excludedKeys = ['file', 'cloud_name', 'resource_type', 'api_key'];
        $filteredParams = array_filter($paramsToSign, fn($key) => !in_array($key, $excludedKeys), ARRAY_FILTER_USE_KEY);

        // Normalizar booleanos
        foreach ($filteredParams as $key => $value) {
            if (is_bool($value)) {
                $filteredParams[$key] = $value ? 'true' : 'false';
            }
        }

        ksort($filteredParams);

        $signatureString = implode('&', array_map(
            fn($k, $v) => "{$k}={$v}",
            array_keys($filteredParams),
            $filteredParams
        ));
        $signatureString .= env('CLOUDINARY_SECRET');

        return sha1($signatureString);
    }

    public function updateProfileImage(int $id, UpdateProfileImageDTO $dto, User $currentUser): array
    {
        $empleado = $this->repository->findById($id);
        if (!$empleado) {
            throw new ModelNotFoundException('Empleado no encontrado');
        }

        $this->checkPermission($empleado, $currentUser);

        $expectedPublicId = "empleados/perfiles/{$id}/profile";
        if ($dto->public_id !== $expectedPublicId) {
            Log::warning('public_id no coincide con la carpeta esperada del empleado', [
                'expected' => $expectedPublicId,
                'received' => $dto->public_id,
                'user_id' => $currentUser->id,
            ]);
            throw new \Exception('Imagen no autorizada para este perfil', 403);
        }

        DB::beginTransaction();
        try {
            $empleado->imagen_perfil = $dto->public_id;
            $empleado->imagen_perfil_url = $dto->secure_url;
            $empleado->save();

            DB::commit();

            return [
                'public_id' => $empleado->imagen_perfil,
                'url' => $empleado->imagen_perfil_url,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updatePassword(int $id, UpdateEmpleadoPasswordDTO $dto): void
    {
        $empleado = $this->repository->findById($id);

        if (!$empleado) {
            throw new ModelNotFoundException('Empleado no encontrado');
        }

        $user = User::find($empleado->id_user);
        if (!$user) {
            throw new ModelNotFoundException('Usuario asociado no encontrado');
        }

        $user->update(['password' => Hash::make($dto->password)]);
    }

    public function verifyPassword(VerifyEmpleadoPasswordDTO $dto): array
    {
        $empleado = $this->repository->findById($dto->id_empleado);

        if (!$empleado) {
            throw new ModelNotFoundException('Empleado no encontrado');
        }

        $user = User::find($empleado->id_user);
        if (!$user) {
            return [
                'valid' => false,
                'message' => 'No se encontró el usuario asociado al empleado',
                'status' => 404
            ];
        }

        if (!Hash::check($dto->currentPassword, $user->password)) {
            return [
                'valid' => false,
                'message' => 'La contraseña actual es incorrecta',
                'status' => 400
            ];
        }

        return [
            'valid' => true,
            'message' => 'Contraseña verificada correctamente',
            'status' => 200
        ];
    }

    public function deleteEmpleado(int $id, User $currentUser): void
    {
        $empleado = $this->repository->findById($id);

        if (!$empleado) {
            throw new ModelNotFoundException('Empleado no encontrado');
        }

        $this->checkPermission($empleado, $currentUser);

        DB::beginTransaction();
        try {
            $user = User::find($empleado->id_user);
            if ($user) {
                Log::info("Eliminando usuario vinculado con ID: " . $user->id);
                $user->delete();
            }

            Log::info("Eliminando empleado con ID: $id");
            $this->repository->delete($empleado);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function deleteProfileImage(int $id, User $currentUser): void
    {
        $empleado = $this->repository->findById($id);
        if (!$empleado) {
            throw new ModelNotFoundException('Empleado no encontrado');
        }

        $this->checkPermission($empleado, $currentUser);

        if ($empleado->imagen_perfil) {
            Log::info('Eliminando imagen de perfil:', ['public_id' => $empleado->imagen_perfil]);
            try {
                $cloudinary = new Cloudinary([
                    'cloud' => [
                        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
                        'api_key'    => env('CLOUDINARY_KEY'),
                        'api_secret' => env('CLOUDINARY_SECRET'),
                    ]
                ]);
                $result = $cloudinary->uploadApi()->destroy($empleado->imagen_perfil);
                Log::info('Resultado de eliminación en Cloudinary:', ['result' => $result]);
            } catch (\Exception $e) {
                Log::warning("Error al eliminar imagen en Cloudinary: " . $e->getMessage());
            }

            $empleado->imagen_perfil = null;
            $empleado->imagen_perfil_url = null;
            $empleado->save();
        }
    }

    private function checkPermission(Empleado $targetEmpleado, User $currentUser): Empleado
    {
        $currentEmpleado = Empleado::where('id_user', $currentUser->id)->first()
            ?: Empleado::where('id_empleado', $currentUser->id)->first();

        if (!$currentEmpleado) {
            throw new \Exception('No se encontró el empleado asociado', 403);
        }

        if (!$targetEmpleado->canBeModifiedBy($currentEmpleado)) {
            Log::warning("Intento no autorizado de modificar empleado restringido", [
                'target_id' => $targetEmpleado->id_empleado,
                'target_email' => $targetEmpleado->email,
                'user_id' => $currentUser->id,
                'user_email' => $currentUser->email
            ]);
            throw new \Exception('No tienes permiso para modificar este empleado', 403);
        }

        return $currentEmpleado;
    }

    private function formatDni(string $dni, bool $isAdmin): string
    {
        if (!$isAdmin && $dni) {
            $length = strlen($dni);
            if ($length > 4) {
                return str_repeat('*', $length - 4) . substr($dni, -4);
            }
        }
        return $dni;
    }

    private function createPassword(string $dni, string $nombre, string $apellidos): string
    {
        $apellidoIniciales = strtoupper(substr($nombre, 0, 2));
        $nombreIniciales = strtolower(substr($apellidos, 0, 2));
        $dniParte = substr($dni, -3);

        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);

        $password = "{$apellidoIniciales}{$dniParte}";

        for ($i = 0; $i < 5; $i++) {
            $password .= $characters[rand(0, $charactersLength - 1)];
        }

        $password .= $nombreIniciales;

        return $password;
    }
}
