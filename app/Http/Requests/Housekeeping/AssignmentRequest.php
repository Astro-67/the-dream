<?php

namespace App\Http\Requests\Housekeeping;

use App\Domains\Housekeeping\Models\Assignment;
use App\Domains\Inventory\Models\Room;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('supervise', Assignment::class) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'business_date' => ['required', 'date_format:Y-m-d'],
            'room_id' => ['required', 'string', Rule::in(Room::query()->active()->pluck('id')->all())],
            'housekeeper_id' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                $user = is_string($value) ? User::query()->find($value) : null;
                if ($user === null || ! Access::allows($user, OrganizationPermission::HousekeepingManage, Tenancy::property())) {
                    $fail(__('Choose a housekeeper with access to this property.'));
                }
            }],
        ];
    }
}
