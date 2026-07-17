<?php

namespace App\Http\Requests\Phase9;

use App\Models\CheckIn;
use Illuminate\Support\Facades\Gate;

class UpdateCheckInRequest extends StoreCheckInRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $checkIn = $this->route('check_in');

        return $checkIn instanceof CheckIn
            && Gate::allows('update', $checkIn);
    }
}
