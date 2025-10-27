<?php

namespace App\Http\Requests;

use App\Helpers\PriceHelper;
use App\Models\ShippingService;
use App\Models\State;
use Illuminate\Foundation\Http\FormRequest;

class PaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {

        return  true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $state = State::whereStatus(1)->count() != 0  ? 'required' : '';
        $shipping = ShippingService::whereStatus(1)->count() == 0 || PriceHelper::CheckDigital() == true? 'required' : '';
       
        return [
            'state_id' => $state,
            "shipping_id" => $shipping,
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'state_id.required'   => __('Please select your shipping state.'),
            'shipping_id.required'   => __('Please select your shipping method.'),
        ];
    }

}
