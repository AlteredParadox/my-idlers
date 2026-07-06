<?php

namespace App\Services;

use Illuminate\Support\Collection;

class CsvFormatter
{
    /**
     * Transform collection to CSV string with header row and proper escaping
     *
     * @param Collection|array $data
     * @param array $headers Optional headers (auto-detected if not provided)
     * @return string
     */
    public function toCsv($data, array $headers = []): string
    {
        if ($data instanceof Collection) {
            $data = $data->toArray();
        }

        if (empty($data)) {
            return empty($headers) ? '' : $this->escapeCsvRow($headers);
        }

        // Flatten all items and collect all possible headers
        $flattenedData = [];
        $allKeys = [];

        foreach ($data as $item) {
            $flattened = $this->flattenForCsv((array) $item);
            $flattenedData[] = $flattened;
            $allKeys = array_merge($allKeys, array_keys($flattened));
        }

        // Use provided headers or auto-detect from data
        if (empty($headers)) {
            $headers = array_unique($allKeys);
        }

        // Build CSV output
        $output = $this->escapeCsvRow($headers) . "\n";

        foreach ($flattenedData as $row) {
            $rowData = [];
            foreach ($headers as $header) {
                $rowData[] = $row[$header] ?? '';
            }
            $output .= $this->escapeCsvRow($rowData) . "\n";
        }

        return rtrim($output, "\n");
    }

    /**
     * Escape a single CSV row with proper handling of special characters
     *
     * @param array $row
     * @return string
     */
    protected function escapeCsvRow(array $row): string
    {
        $escapedFields = [];

        foreach ($row as $field) {
            $escapedFields[] = $this->escapeCsvField($field);
        }

        return implode(',', $escapedFields);
    }

    /**
     * Escape a single CSV field value, neutralizing spreadsheet formulas
     *
     * @param mixed $value
     * @return string
     */
    protected function escapeCsvField($value): string
    {
        $value = $this->stringify($value);

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $value = "'" . $value;
        }

        // Quote if the field contains a comma, double quote, newline, or carriage return
        if (strpbrk($value, ",\"\n\r") !== false) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    protected function stringify($value): string
    {
        return match (true) {
            is_null($value) => '',
            is_bool($value) => $value ? '1' : '0',
            is_array($value) => (string) json_encode($value),
            default => (string) $value,
        };
    }

    /**
     * Flatten nested data for CSV export with appropriate prefixes
     *
     * @param array $item
     * @param string $prefix
     * @return array
     */
    protected function flattenForCsv(array $item, string $prefix = ''): array
    {
        $result = [];

        foreach ($item as $key => $value) {
            $newKey = $prefix === '' ? $key : $prefix . '_' . $key;

            if (is_array($value)) {
                $result = array_merge($result, $this->flattenArray($value, $newKey));
            } elseif (is_object($value)) {
                $result = array_merge($result, $this->flattenForCsv((array) $value, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    protected function flattenArray(array $value, string $newKey): array
    {
        if (!$this->isIndexedArray($value)) {
            // Associative array - recursively flatten
            return $this->flattenForCsv($value, $newKey);
        }

        if ($this->isArrayOfScalars($value)) {
            // Simple list of scalars - join with semicolon
            return [$newKey => implode(';', $value)];
        }

        // Complex list - store as JSON
        return [$newKey => json_encode($value)];
    }

    /**
     * Check if an array is indexed (sequential numeric keys starting from 0)
     *
     * @param array $array
     * @return bool
     */
    protected function isIndexedArray(array $array): bool
    {
        if (empty($array)) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }

    /**
     * Check if an array contains only scalar values
     *
     * @param array $array
     * @return bool
     */
    protected function isArrayOfScalars(array $array): bool
    {
        foreach ($array as $value) {
            if (!is_scalar($value) && !is_null($value)) {
                return false;
            }
        }

        return true;
    }
}
