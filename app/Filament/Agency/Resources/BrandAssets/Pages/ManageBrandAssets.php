<?php

namespace App\Filament\Agency\Resources\BrandAssets\Pages;

use App\Filament\Agency\Resources\BrandAssets\BrandAssetResource;
use App\Models\Brand;
use App\Models\BrandAsset;
use App\Models\Workspace;
use App\Services\Imagery\BrandAssetTagger;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Storage;

class ManageBrandAssets extends ManageRecords
{
    protected static string $resource = BrandAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')
                ->label('Upload assets')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->modalHeading('Upload brand assets')
                ->modalDescription('Drop in brand-approved images / videos. They go through Claude vision tagging on save (~3s per image, $0.01 each).')
                ->schema([
                    Select::make('brand_id')
                        ->label('Brand')
                        ->options(fn () => $this->brandOptions())
                        ->default(fn () => $this->defaultBrandId())
                        ->required(),
                    FileUpload::make('files')
                        ->label('Files (drag-drop, multiple allowed)')
                        ->multiple()
                        ->disk($this->preferredDisk())
                        ->directory(fn (callable $get) => 'brand-assets/' . ($get('brand_id') ?: 'unsorted'))
                        ->visibility('public')
                        ->preserveFilenames()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/quicktime', 'video/webm'])
                        ->maxSize(50 * 1024) // 50 MB per file
                        ->required(),
                ])
                ->action(function (array $data): void {
                    @set_time_limit(300);
                    $disk = $this->preferredDisk();
                    $brandId = (int) $data['brand_id'];
                    $files = $data['files'] ?? [];
                    if (! is_array($files)) $files = [$files];

                    $created = 0;
                    foreach ($files as $relativePath) {
                        $absoluteUrl = Storage::disk($disk)->url($relativePath);
                        $isVideo = str_starts_with(Storage::disk($disk)->mimeType($relativePath) ?: '', 'video/');
                        $asset = BrandAsset::create([
                            'brand_id' => $brandId,
                            'uploaded_by_user_id' => auth()->id(),
                            'media_type' => $isVideo ? 'video' : 'image',
                            'source' => 'upload',
                            'storage_disk' => $disk,
                            'storage_path' => $relativePath,
                            'public_url' => $absoluteUrl,
                            'original_filename' => basename($relativePath),
                            'mime_type' => Storage::disk($disk)->mimeType($relativePath) ?: null,
                            'file_size_bytes' => Storage::disk($disk)->size($relativePath) ?: null,
                            'brand_approved' => true,
                        ]);

                        try {
                            app(BrandAssetTagger::class)->tag($asset);
                        } catch (\Throwable $e) {
                            // Tagging is best-effort; the asset still lives.
                            \Illuminate\Support\Facades\Log::warning('upload: tagger failed', [
                                'asset_id' => $asset->id,
                                'error' => $e->getMessage(),
                            ]);
                        }

                        $created++;
                    }

                    Notification::make()
                        ->title("Uploaded {$created} asset(s)")
                        ->body('Tagging + embedding ran inline. Designer/Video agents will start picking from these immediately.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /** R2 if env is configured, else local public disk. */
    private function preferredDisk(): string
    {
        return config('filesystems.disks.r2.bucket') ? 'r2' : 'public';
    }

    /** @return array<int, string> brand_id => display name */
    private function brandOptions(): array
    {
        $ws = $this->workspace();
        if (! $ws) return [];
        return Brand::where('workspace_id', $ws->id)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->pluck('name', 'id')
            ->all();
    }

    private function defaultBrandId(): ?int
    {
        $ws = $this->workspace();
        if (! $ws) return null;
        return Brand::where('workspace_id', $ws->id)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->value('id');
    }

    private function workspace(): ?Workspace
    {
        $user = auth()->user();
        if (! $user) return null;
        return $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();
    }
}
