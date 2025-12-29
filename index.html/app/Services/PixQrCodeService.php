<?php

namespace App\Services;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class PixQrCodeService
{
    /**
     * Gera um QR code a partir de um código PIX e insere logo no centro
     *
     * @param string $pixCode Código PIX copia e cola
     * @param string $logoPath Caminho para o arquivo da logo
     * @param int $size Tamanho do QR code em pixels
     * @return string Imagem QR code com logo em base64
     */
    public function generateQrCodeWithLogo(string $pixCode, string $logoPath, int $size = 300): string
    {
        try {
            // Inicializa o gerenciador de imagens com o driver GD
            $manager = new ImageManager(new Driver());

            // Configurações do QR code com versão maior para suportar mais dados
            $options = new QROptions([
                'version'      => 10, // Versão aumentada para suportar códigos PIX maiores
                'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
                'eccLevel'     => QRCode::ECC_H, // Alta correção de erro para a logo
                'scale'        => 5,
                'imageBase64'  => false,
            ]);

            // Gera o QR code
            $qrcode = (new QRCode($options))->render($pixCode);

            // Converte o QR code para uma imagem manipulável
            $qrImage = $manager->read($qrcode);

            // Redimensiona para o tamanho desejado
            $qrImage->resize($size, $size);

            // Carrega e redimensiona a logo
            $logo = $manager->read($logoPath);
            $logoSize = (int)($size * 0.9); // Logo com 40% do tamanho do QR code
            $logo->resize($logoSize, $logoSize);

            // Calcula posição central para a logo
            $centerX = ($qrImage->width() / 2) - ($logoSize / 2);
            $centerY = ($qrImage->height() / 2) - ($logoSize / 2);

            // Insere a logo no centro do QR code
            $qrImage->place($logo, 'top-left', (int)$centerX, (int)$centerY);

            // Converte para base64
            return 'data:image/png;base64,' . base64_encode($qrImage->toJpeg());
        } catch (\Exception $e) {
            // Log do erro
            \Log::error('Erro ao gerar QR code: ' . $e->getMessage());

            // Tenta gerar com versão ainda maior se houver erro de capacidade
            if (strpos($e->getMessage(), 'code length overflow') !== false) {
                return $this->generateQrCodeWithHigherVersion($pixCode, $logoPath, $size);
            }

            throw $e;
        }
    }

    /**
     * Tenta gerar QR code com versão mais alta para códigos maiores
     */
    private function generateQrCodeWithHigherVersion(string $pixCode, string $logoPath, int $size): string
    {
        // Inicializa o gerenciador de imagens com o driver GD
        $manager = new ImageManager(new Driver());

        // Configurações com versão máxima
        $options = new QROptions([
            'version'      => QRCode::VERSION_AUTO, // Versão automática
            'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
            'eccLevel'     => QRCode::ECC_M, // Correção de erro média para equilibrar tamanho/capacidade
            'scale'        => 5,
            'imageBase64'  => false,
        ]);

        // Gera o QR code
        $qrcode = (new QRCode($options))->render($pixCode);

        // Converte o QR code para uma imagem manipulável
        $qrImage = $manager->read($qrcode);

        // Redimensiona para o tamanho desejado
        $qrImage->resize($size, $size);

        // Carrega e redimensiona a logo
        $logo = $manager->read($logoPath);
        $logoSize = (int)($size * 0.18); // Logo um pouco menor para QR codes mais densos
        $logo->resize($logoSize, $logoSize);

        // Calcula posição central para a logo
        $centerX = ($qrImage->width() / 2) - ($logoSize / 2);
        $centerY = ($qrImage->height() / 2) - ($logoSize / 2);

        // Insere a logo no centro do QR code
        $qrImage->place($logo, 'top-left', (int)$centerX, (int)$centerY);

        // Converte para base64
        return 'data:image/png;base64,' . base64_encode($qrImage->toJpeg());
    }

    /**
     * Valida se o código PIX está em um formato válido
     * 
     * @param string $pixCode Código PIX copia e cola
     * @return bool
     */
    public function validatePixCode(string $pixCode): bool
    {
        // Implementar validação de código PIX (formato básico)
        return !empty($pixCode) && strlen($pixCode) >= 10;
    }
}
