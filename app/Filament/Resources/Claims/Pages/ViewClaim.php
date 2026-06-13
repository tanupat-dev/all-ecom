<?php

namespace App\Filament\Resources\Claims\Pages;

use App\Actions\Claims\TransitionClaimStatus;
use App\Enums\ClaimStatus;
use App\Filament\Resources\Claims\ClaimResource;
use App\Models\Claim;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * View page for a single Claim (Issue #84). Exposes the two-stage lifecycle
 * transition via a header Action; the select lists only legal next states
 * (sourced from TransitionClaimStatus::nextStates, single source of truth).
 * Mutation is gated on `claim.manage` (ClaimPolicy); view-only users see the
 * page but the action button is hidden / unauthorized.
 */
class ViewClaim extends ViewRecord
{
    protected static string $resource = ClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('transition')
                ->label('เปลี่ยนสถานะ')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (Claim $record): bool => ! $record->status->isTerminal())
                ->authorize(fn (Claim $record): bool => auth()->user()?->can('update', $record) ?? false)
                ->schema([
                    Select::make('status')
                        ->label('สถานะใหม่')
                        ->required()
                        ->options(fn (Claim $record): array => collect(app(TransitionClaimStatus::class)->nextStates($record->status))
                            ->mapWithKeys(fn (ClaimStatus $s): array => [$s->value => $s->value])
                            ->all()),
                ])
                ->action(function (Claim $record, array $data): Claim {
                    $status = $data['status'];

                    if (! is_string($status)) {
                        throw new \InvalidArgumentException('Expected string for status.');
                    }

                    return app(TransitionClaimStatus::class)->handle($record, ClaimStatus::from($status));
                }),
        ];
    }
}
