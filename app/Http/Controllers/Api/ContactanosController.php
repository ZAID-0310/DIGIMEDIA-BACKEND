<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContactanosService;
use App\DTOs\Contactanos\CreateContactanosDTO;
use App\DTOs\Contactanos\UpdateContactanosDTO;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ContactanosController extends Controller
{
    public function __construct(
        private ContactanosService $contactanosService
    ) {}

    public function get(Request $request)
    {
        //llamaba a solo 4 contactos ahora se corrigio a 10
        $contactos = $this->contactanosService->getContactos(10);
        return response()->json($contactos, 200);
    }

    public function getById($id)
    {
        try {
            $contacto = $this->contactanosService->getContactoById((int)$id);

            return response()->json([
                'status' => 'success',
                'data' => $contacto
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Contacto no encontrado'], 404);
        }
    }

    public function create(Request $request)
    {
        $validated = Validator::make($request->all(), [
            'nombre'   => 'required|string|max:255',
            'email'    => 'required|email|max:255',
            'numero'   => 'required|string|max:20',
            'mensaje'  => 'required|string|max:1050',
            'servicio' => 'nullable|string|max:100',
        ]);

        if ($validated->fails()) {
            return response()->json(['errors' => $validated->errors()], 422);
        }

        $dto = CreateContactanosDTO::fromRequest($request);
        $this->contactanosService->createContacto($dto);

        return response()->json([
            'status' => 201,
            'message' => 'Contacto guardado exitosamente'
        ], 201);
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'estado' => 'required|boolean',
            ]);

            $dto = UpdateContactanosDTO::fromRequest($request);
            $contacto = $this->contactanosService->updateContacto((int)$id, $dto);

            return response()->json([
                'message' => 'Estado actualizado exitosamente',
                'data' => $contacto,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Contacto no encontrado'], 404);
        }
    }

    public function delete($id)
    {
        try {
            $this->contactanosService->deleteContacto((int)$id);

            return response()->json([
                'message' => 'Contacto eliminado exitosamente'
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Contacto no encontrado'], 404);
        }
    }
}