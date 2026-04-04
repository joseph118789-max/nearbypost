<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class SubscriberRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $rules = [
            'user_code' => 'sometimes|required|string|max:50|unique:users,user_code,' . ($this->route('id') ?? 'NULL'),
            'mobile' => 'sometimes|required|string|max:30',
            'wa_group' => 'sometimes|nullable|string|max:100',
            'interest_sub_cat' => 'sometimes|nullable|string|max:100',
            'status' => 'sometimes|in:active,inactive,pending',
            'location_name' => 'sometimes|nullable|string|max:255',
            'join_date' => 'sometimes|nullable|date',
        ];

        if ($this->isMethod('post')) {
            $rules['user_code'] = 'required|string|max:50|unique:users,user_code';
            $rules['mobile'] = 'required|string|max:30';
        }

        return $rules;
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated($key, $default);
        if (!isset($data['name']) && isset($data['user_code'])) {
            $data['name'] = $data['user_code'];
        }
        if (!isset($data['email']) && isset($data['user_code'])) {
            $data['email'] = $data['user_code'] . '@nearbypost.local';
        }
        return $data;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $validator->errors()], 422));
    }
}
