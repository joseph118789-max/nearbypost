<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class NewsItemRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $rules = [
            'title' => 'sometimes|required|string|max:500',
            'headline' => 'sometimes|required|string|max:500',
            'summary' => 'sometimes|nullable|string|max:2000',
            'primary_cat' => 'sometimes|nullable|string|max:100',
            'primary_category' => 'sometimes|nullable|string|max:100',
            'sub_cat' => 'sometimes|nullable|string|max:100',
            'secondary_category' => 'sometimes|nullable|string|max:100',
            'status' => 'sometimes|nullable|in:pending_extraction,active';,
            'relevance_mode' => 'sometimes|nullable|in:hybrid,location_only,category_only',
            'precision_type' => 'sometimes|nullable|in:exact_area,approximate_area,state_center,region,country,national,unresolved',
            'main_place_text' => 'sometimes|nullable|string|max:255',
            'location_label' => 'sometimes|nullable|string|max:255',
            'lat' => 'sometimes|nullable|numeric|between:-90,90',
            'lng' => 'sometimes|nullable|numeric|between:-180,180',
            'source_name' => 'sometimes|nullable|string|max:255',
            'source' => 'sometimes|nullable|string|max:255',
            'source_url' => 'sometimes|nullable|url|max:500',
            'url' => 'sometimes|nullable|url|max:500',
            'published_at' => 'sometimes|nullable|date',
            'datetime' => 'sometimes|nullable|date',
        ];

        if ($this->isMethod('post')) {
            $rules['headline'] = 'required_without:title|string|max:500';
            $rules['title'] = 'required_without:headline|string|max:500';
            $rules['primary_cat'] = 'required_without:primary_category|string|max:100';
            $rules['primary_category'] = 'required_without:primary_cat|string|max:100';
        }

        return $rules;
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated($key, $default);

        // Normalize field names to match DB columns
        if (isset($data['headline']) && !isset($data['title'])) {
            $data['title'] = $data['headline'];
        }
        if (isset($data['primary_cat']) && !isset($data['primary_category'])) {
            $data['primary_category'] = $data['primary_cat'];
        }
        if (isset($data['sub_cat']) && !isset($data['secondary_category'])) {
            $data['secondary_category'] = $data['sub_cat'];
        }
        if (isset($data['source_url']) && !isset($data['url'])) {
            $data['url'] = $data['source_url'];
        }
        if (isset($data['datetime']) && !isset($data['published_at'])) {
            $data['published_at'] = $data['datetime'];
        }
        if (isset($data['location_label']) && !isset($data['main_place_text'])) {
            $data['main_place_text'] = $data['location_label'];
        }

        // Remove frontend alias fields
        unset($data['headline'], $data['primary_cat'], $data['sub_cat'], $data['source_url'], $data['datetime'], $data['location_label']);

        return $data;
    }

    public function messages(): array
    {
        return [
            'headline.required_without' => 'The headline is required.',
            'primary_cat.required_without' => 'The primary category is required.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $validator->errors()], 422));
    }
}
