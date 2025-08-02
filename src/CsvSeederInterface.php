<?php

namespace Aurabx\CsvSeeder;

interface CsvSeederInterface
{
    /**
     * @return void
     */
    public function configure(): void;

    public function getDbColumns(): array;
}
