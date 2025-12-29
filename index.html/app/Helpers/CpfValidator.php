<?php

namespace App\Helpers;

class CpfValidator
{
    /**
     * Valida um número de CPF
     *
     * @param string $cpf CPF a ser validado
     * @return bool Retorna true se o CPF for válido, false caso contrário
     */
    public static function isValid($cpf)
    {
        // Remove caracteres não numéricos
        $cpf = preg_replace('/[^0-9]/', '', $cpf);

        // Verifica se o CPF tem 11 dígitos
        if (strlen($cpf) != 11) {
            return false;
        }

        // Verifica se todos os dígitos são iguais
        if (preg_match('/^(\d)\1+$/', $cpf)) {
            return false;
        }

        // Calcula o primeiro dígito verificador
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $cpf[$i] * (10 - $i);
        }
        $remainder = $sum % 11;
        $digit1 = $remainder < 2 ? 0 : 11 - $remainder;

        // Verifica o primeiro dígito verificador
        if ($digit1 != (int) $cpf[9]) {
            return false;
        }

        // Calcula o segundo dígito verificador
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int) $cpf[$i] * (11 - $i);
        }
        $remainder = $sum % 11;
        $digit2 = $remainder < 2 ? 0 : 11 - $remainder;

        // Verifica o segundo dígito verificador
        if ($digit2 != (int) $cpf[10]) {
            return false;
        }

        return true;
    }

    /**
     * Formata um CPF para o formato XXX.XXX.XXX-XX
     *
     * @param string $cpf CPF a ser formatado
     * @return string CPF formatado
     */
    public static function format($cpf)
    {
        // Remove caracteres não numéricos
        $cpf = preg_replace('/[^0-9]/', '', $cpf);

        // Formata o CPF
        if (strlen($cpf) === 11) {
            return sprintf(
                '%s.%s.%s-%s',
                substr($cpf, 0, 3),
                substr($cpf, 3, 3),
                substr($cpf, 6, 3),
                substr($cpf, 9, 2)
            );
        }

        return $cpf;
    }

    /**
     * Remove a formatação de um CPF, deixando apenas números
     *
     * @param string $cpf CPF formatado
     * @return string CPF apenas com números
     */
    public static function unformat($cpf)
    {
        return preg_replace('/[^0-9]/', '', $cpf);
    }

    /**
     * Verifica se um CPF é válido e gera uma mensagem de erro caso não seja
     *
     * @param string $cpf CPF a ser validado
     * @return array ['valid' => bool, 'message' => string|null]
     */
    public static function validate($cpf)
    {
        // Remove formatação
        $cpf = self::unformat($cpf);

        // Verifica se está vazio
        if (empty($cpf)) {
            return [
                'valid' => false,
                'message' => 'O CPF é obrigatório.'
            ];
        }

        // Verifica se tem o tamanho correto
        if (strlen($cpf) !== 11) {
            return [
                'valid' => false,
                'message' => 'O CPF deve conter 11 dígitos.'
            ];
        }

        // Verifica se todos os dígitos são iguais
        if (preg_match('/^(\d)\1+$/', $cpf)) {
            return [
                'valid' => false,
                'message' => 'CPF inválido.'
            ];
        }

        // Valida o CPF usando o algoritmo
        if (!self::isValid($cpf)) {
            return [
                'valid' => false,
                'message' => 'O CPF informado é inválido.'
            ];
        }

        return [
            'valid' => true,
            'message' => null
        ];
    }

    /**
     * Gera um número de CPF válido (útil para testes)
     *
     * @param bool $formatted Determina se o CPF gerado deve ser formatado
     * @return string CPF válido
     */
    public static function generate($formatted = false)
    {
        $n1 = rand(0, 9);
        $n2 = rand(0, 9);
        $n3 = rand(0, 9);
        $n4 = rand(0, 9);
        $n5 = rand(0, 9);
        $n6 = rand(0, 9);
        $n7 = rand(0, 9);
        $n8 = rand(0, 9);
        $n9 = rand(0, 9);

        // Calcula o primeiro dígito verificador
        $sum = ($n1 * 10) + ($n2 * 9) + ($n3 * 8) + ($n4 * 7) + ($n5 * 6) + ($n6 * 5) + ($n7 * 4) + ($n8 * 3) + ($n9 * 2);
        $remainder = $sum % 11;
        $d1 = $remainder < 2 ? 0 : 11 - $remainder;

        // Calcula o segundo dígito verificador
        $sum = ($n1 * 11) + ($n2 * 10) + ($n3 * 9) + ($n4 * 8) + ($n5 * 7) + ($n6 * 6) + ($n7 * 5) + ($n8 * 4) + ($n9 * 3) + ($d1 * 2);
        $remainder = $sum % 11;
        $d2 = $remainder < 2 ? 0 : 11 - $remainder;

        // Monta o CPF
        $cpf = "{$n1}{$n2}{$n3}{$n4}{$n5}{$n6}{$n7}{$n8}{$n9}{$d1}{$d2}";

        // Retorna formatado ou não conforme solicitado
        return $formatted ? self::format($cpf) : $cpf;
    }
}
