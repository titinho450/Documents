<?php

namespace App\Services\PayOne\DTO;

/**
 * @property-read string $code Código PIX
 * @property-read string $base64 PIX em formato base64
 * @property-read string $image URL da imagem do PIX
 */
class PayOnePixData
{
    public string $code;
    public string $base64;
    public string $image;

    public function __construct(array $data)
    {
        $this->code = $data['code'] ?? throw new \InvalidArgumentException("Pix 'code' is required.");
        $this->base64 = $data['base64'] ?? throw new \InvalidArgumentException("Pix 'base64' is required.");
        $this->image = $data['image'] ?? throw new \InvalidArgumentException("Pix 'image' is required.");
    }
}

/**
 * Classe que representa a resposta de um depósito PIX
 * @property-read string $transactionId ID da transação
 * @property-read string $status Status da transação
 * @property-read PayOnePix $pix Dados do PIX
 */
class PayOneDepositResponse
{
    public string $transactionId;
    public string $status;
    public PayOnePix $pix;

    public function __construct(array $data)
    {
        if (!isset($data['transactionId'], $data['status'], $data['pix'])) {
            throw new \InvalidArgumentException('Resposta inválida da API.');
        }

        $this->transactionId = $data['transactionId'];
        $this->status = $data['status'];
        $this->pix = new PayOnePix($data['pix']);
    }
}

/**
 * Classe que representa os dados do PIX
 * @property-read string $code Código PIX
 * @property-read string $base64 PIX em formato base64
 * @property-read string $image URL da imagem do PIX
 */
class PayOnePix
{
    public string $code;
    public string $base64;
    public string $image;

    public function __construct(array $data)
    {
        if (!isset($data['code'], $data['base64'], $data['image'])) {
            throw new \InvalidArgumentException('Dados PIX inválidos.');
        }

        $this->code = $data['code'];
        $this->base64 = $data['base64'];
        $this->image = $data['image'];
    }
}
