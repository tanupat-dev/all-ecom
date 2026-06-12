<?php

namespace App\Filament\Resources\ImportJobs;

use App\Filament\Resources\ImportJobs\Pages\ListImportJobs;
use App\Models\ImportJob;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only list of ImportJobs (ROADMAP Phase 9 B, ADR 0019).
 *
 * The table is tenant-scoped via BelongsToTenant on ImportJob. The single
 * record action "ดาวน์โหลดไฟล์ที่เติมแล้ว" is:
 *  - visible only when context['result_path'] is set (a fill completed),
 *  - gated on `listing.manage` (same permission that triggers a fill).
 *
 * The resource is accessible to any user with `listing.manage`; the list
 * itself shows only that tenant's jobs.
 */
class ImportJobResource extends Resource
{
    protected static ?string $model = ImportJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $modelLabel = 'งานนำเข้า';

    protected static ?string $navigationLabel = 'งานนำเข้า (Import Jobs)';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('importer')
                    ->label('ประเภท')
                    ->state(fn (ImportJob $record): string => class_basename($record->importer)),
                TextColumn::make('original_filename')
                    ->label('ไฟล์ต้นฉบับ')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('สถานะ')
                    ->badge(),
                TextColumn::make('processed_rows')
                    ->label('แถวที่ประมวลผล'),
                TextColumn::make('error_rows')
                    ->label('แถวที่มีข้อผิดพลาด'),
                TextColumn::make('created_at')
                    ->label('สร้างเมื่อ')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                // Stream the filled Channel Upload Template xlsx stored by
                // RunTemplateFillJob. Only visible once the fill has written
                // a result_path into the ImportJob context. Gated on
                // listing.manage via ImportJobPolicy::downloadFilledResult.
                Action::make('downloadFilledResult')
                    ->label('ดาวน์โหลดไฟล์ที่เติมแล้ว')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (ImportJob $record): bool => isset($record->context['result_path']))
                    ->authorize(fn (ImportJob $record): bool => auth()->user()?->can('downloadFilledResult', $record) ?? false)
                    ->action(function (ImportJob $record): StreamedResponse {
                        $context = $record->context;
                        $resultPath = is_array($context) ? ($context['result_path'] ?? null) : null;

                        if (! is_string($resultPath) || $resultPath === '') {
                            throw new LogicException('No result file stored for this import job.');
                        }

                        $content = Storage::disk('local')->get($resultPath);

                        if ($content === null) {
                            throw new LogicException("Result file not found at path: {$resultPath}");
                        }

                        // Derive the platform prefix from the filler class name:
                        // ShopeeTemplateFiller → shopee
                        $platform = strtolower(str_replace('TemplateFiller', '', class_basename($record->importer)));
                        $filename = "{$platform}-template-filled-{$record->id}.xlsx";

                        return response()->streamDownload(
                            static function () use ($content): void {
                                echo $content;
                            },
                            $filename,
                            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                        );
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportJobs::route('/'),
        ];
    }
}
