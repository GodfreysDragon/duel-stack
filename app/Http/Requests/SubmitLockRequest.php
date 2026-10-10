<?php

namespace App\Http\Requests;

use App\Game\Pieces;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Where the client's piece came to rest. Game legality is checked by
 * App\Game\Simulation; this only guards the shape of the input.
 */
class SubmitLockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'piece_number' => ['required', 'integer', 'min:0'],
            'piece' => ['required', 'string', Rule::in(Pieces::TYPES)],
            'rotation' => ['required', 'integer', 'between:0,3'],
            'x' => ['required', 'integer', 'between:-3,12'],
            'y' => ['required', 'integer', 'between:-3,24'],
            'held' => ['required', 'boolean'],
            'garbage_ids' => ['present', 'array', 'max:50'],
            'garbage_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array{piece_number: int, piece: string, rotation: int, x: int, y: int, held: bool, garbage_ids: list<int>}
     */
    public function lock(): array
    {
        return [
            'piece_number' => $this->integer('piece_number'),
            'piece' => $this->string('piece')->value(),
            'rotation' => $this->integer('rotation'),
            'x' => $this->integer('x'),
            'y' => $this->integer('y'),
            'held' => $this->boolean('held'),
            'garbage_ids' => array_map(intval(...), array_values($this->validated('garbage_ids'))),
        ];
    }
}
