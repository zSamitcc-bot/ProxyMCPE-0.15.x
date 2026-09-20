<?php

namespace kuoto\utils;

/**
 * Dibuja las tablas con marco de la consola.
 *
 * Antes cada comando calculaba a mano sus separadores y su relleno con
 * str_repeat()/str_pad(), y como el relleno contaba tambien los bytes de los
 * codigos ANSI, las columnas se descuadraban en cuanto una celda tenia color.
 * Aqui el ancho se mide siempre sobre el texto limpio.
 */
class ConsoleTable
{
    /** @var string */
    private $title;
    /** @var string color del marco */
    private $frameColor;
    /** @var string[] */
    private $headers = array();
    /** @var int[] */
    private $widths = array();
    /** @var string[][] */
    private $rows = array();

    /**
     * @param string $title
     * @param string $frameColor
     */
    public function __construct($title, $frameColor = TextFormat::GOLD)
    {
        $this->title = $title;
        $this->frameColor = $frameColor;
    }

    /**
     * @param string[] $headers
     * @param int[] $widths ancho de cada columna
     * @return $this
     */
    public function setHeaders(array $headers, array $widths)
    {
        $this->headers = $headers;
        $this->widths = $widths;
        return $this;
    }

    /**
     * @param string[] $cells
     * @return $this
     */
    public function addRow(array $cells)
    {
        $this->rows[] = $cells;
        return $this;
    }

    /** @return string */
    public function render()
    {
        $inner = $this->getInnerWidth();
        $line = $this->frameColor . '+' . str_repeat('-', $inner) . '+' . TextFormat::RESET . "\n";

        $out = "\n" . $line;
        $out .= $this->renderLine(TextFormat::WHITE . TextFormat::BOLD . $this->title, $inner);
        $out .= $line;

        if (!empty($this->headers)) {
            $cells = array();
            foreach ($this->headers as $i => $header) {
                $cells[] = TextFormat::AQUA . $header . TextFormat::RESET;
            }
            $out .= $this->renderLine($this->joinCells($cells), $inner);
            $out .= TextFormat::DARK_GRAY . '+' . str_repeat('-', $inner) . '+' . TextFormat::RESET . "\n";
        }

        foreach ($this->rows as $row) {
            $out .= $this->renderLine($this->joinCells($row), $inner);
        }

        $out .= $line;
        return $out;
    }

    public function display()
    {
        echo $this->render();
    }

    /**
     * @param string[] $cells
     * @return string
     */
    private function joinCells(array $cells)
    {
        $parts = array();
        foreach ($cells as $i => $cell) {
            $width = isset($this->widths[$i]) ? $this->widths[$i] : TextFormat::width($cell);
            $parts[] = TextFormat::pad($cell . TextFormat::RESET, $width);
        }
        return implode(' ', $parts);
    }

    /**
     * @param string $content
     * @param int $inner
     * @return string
     */
    private function renderLine($content, $inner)
    {
        $content = ' ' . $content;
        return $this->frameColor . '|' . TextFormat::RESET
            . TextFormat::pad($content, $inner)
            . $this->frameColor . '|' . TextFormat::RESET . "\n";
    }

    /** @return int */
    private function getInnerWidth()
    {
        $width = TextFormat::width($this->title) + 2;
        $columns = array_sum($this->widths) + max(0, count($this->widths) - 1) + 2;
        if ($columns > $width) {
            $width = $columns;
        }
        foreach ($this->rows as $row) {
            $rowWidth = TextFormat::width($this->joinCells($row)) + 2;
            if ($rowWidth > $width) {
                $width = $rowWidth;
            }
        }
        return $width;
    }
}
