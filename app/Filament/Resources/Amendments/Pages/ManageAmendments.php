<?php

namespace App\Filament\Resources\Amendments\Pages;

use App\Filament\Resources\Amendments\AmendmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAmendments extends ManageRecords
{
    protected static string $resource = AmendmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
