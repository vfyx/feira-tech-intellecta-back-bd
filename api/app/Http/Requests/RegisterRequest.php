<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // O front de teste não envia username; o Intellecta pode enviá-lo.
        // Campo enviado vazio continua inválido, em vez de ser ignorado.
        if ($this->exists('username') && is_string($this->input('username'))) {
            $this->merge([
                'username' => strtolower(ltrim(trim($this->input('username')), '@')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:255|unique:usuarios,email',
            'username' => [
                'sometimes',
                'required',
                'string',
                'regex:/\A[a-z0-9_]{3,30}\z/',
                'unique:usuarios,username',
            ],
            'password' => 'required|string|min:8|confirmed',
        ];
    }
}
