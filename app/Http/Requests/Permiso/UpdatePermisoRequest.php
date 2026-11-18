<?php

namespace App\Http\Requests\Permiso;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePermisoRequest extends FormRequest
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
     */
    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'nombre' => [
                'sometimes',
                'string',
                'max:25',
                Rule::unique('permisos', 'nombre')->ignore($id, 'id_permiso'),
            ],
            'descripcion' => [
                'sometimes', 
                'nullable',
                'string',
                'max:100',
            ],
        ];
    }
}
