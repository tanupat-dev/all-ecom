<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Actions\Authorization\DeleteRole;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Role;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Through the Action: strips holders first + lock-out safeguard
            // (ADR 0012) — never a raw delete.
            Action::make('delete')
                ->label('ลบบทบาท')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('ผู้ใช้ที่ถือบทบาทนี้จะถูกถอดออกจากบทบาทก่อนลบ')
                ->action(function (Role $record): void {
                    app(DeleteRole::class)->handle($record, auth()->user()?->tenant_id);

                    $this->redirect(RoleResource::getUrl('index'));
                }),
        ];
    }
}
