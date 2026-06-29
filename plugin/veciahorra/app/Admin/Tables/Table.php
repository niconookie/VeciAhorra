<?php

declare(strict_types=1);

namespace VeciAhorra\Admin\Tables;

final class Table
{
    /**
     * @var Column[]
     */
    private array $columns = [];

    /**
     * @var Action[]
     */
    private array $actions = [];

    private int $perPage = 20;

    private ?string $repository = null;

    /**
    * Título de la tabla.
    */
    private ?string $title = null;

    /**
    * Descripción de la tabla.
    */
    private ?string $description = null;

    /**
    * Columna de ordenamiento por defecto.
    */
    private ?string $defaultSort = null;

    /**
    * Dirección del ordenamiento.
    */
    private string $defaultDirection = 'ASC';

    private function __construct()
    {
    }

    public static function make(
        ?string $repository = null
    ): self {

        $table = new self();

        $table->repository = $repository;

        return $table;
    }

   /**
    * Agrega una columna.
    */
    public function column(
    Column $column
): self {

    $this->columns[] = $column;

    return $this;
}

    public function action(
        Action $action
    ): self {

        $this->actions[] = $action;

        return $this;
    }

    public function perPage(
        int $rows
    ): self {

        $this->perPage = $rows;

        return $this;
    }

    /**
     * @return Column[]
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return Action[]
     */
    public function actions(): array
    {
        return $this->actions;
    }

    public function repository(): ?string
    {
        return $this->repository;
    }

    public function perPageValue(): int
    {
        return $this->perPage;
    }

    /**
    * Define el título.
    */
    public function title(
        string $title
    ): self {

        $this->title = $title;

        return $this;
    }

    /**
    * Define la descripción.
    */
    public function description(
        string $description
    ): self {

        $this->description = $description;

        return $this;
    }

    /**
    * Orden por defecto.
    */
    public function defaultSort(
        string $column,
        string $direction = 'ASC'
    ): self {

        $this->defaultSort = $column;

        $this->defaultDirection = strtoupper($direction);

        return $this;
    }

    /**
    * Obtiene el título.
    */
    public function titleValue(): ?string
    {
        return $this->title;
    }

    /**
    * Obtiene la descripción.
    */
    public function descriptionValue(): ?string
    {
        return $this->description;
    }

    /**
    * Columna de ordenamiento.
    */
    public function defaultSortColumn(): ?string
    {
         return $this->defaultSort;
    }

    /**
    * Dirección del ordenamiento.
    */
    public function defaultSortDirection(): string
    {
         return $this->defaultDirection;
    }

}