<?php

namespace App\Http\Requests\Campania;

use Illuminate\Foundation\Http\FormRequest;

class CampaniaFiltersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'estado' => ['nullable', 'string', 'in:borrador,pendiente,en_proceso,pausada,pausada_hasta_mañana,pausada_fuera_horario,pausada_sin_conexion,completada,cancelada,eliminada,error'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
