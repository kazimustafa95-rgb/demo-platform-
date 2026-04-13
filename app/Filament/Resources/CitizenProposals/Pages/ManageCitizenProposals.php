<?php

namespace App\Filament\Resources\CitizenProposals\Pages;

use App\Filament\Resources\CitizenProposals\CitizenProposalResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCitizenProposals extends ManageRecords
{
    protected static string $resource = CitizenProposalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
