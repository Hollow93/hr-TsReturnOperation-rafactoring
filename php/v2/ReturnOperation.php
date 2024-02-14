<?php

namespace NW\WebService\References\Operations\Notification;

class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW = 1;
    public const TYPE_CHANGE = 2;

    public function doOperation(array $requestData): array
    {
        $this->validateRequestData($requestData);

        $resellerId = (int)$requestData['resellerId'];
        $notificationType = (int)$requestData['notificationType'];

        $client = $this->getContractor(Contractor::class, (int)$requestData['clientId'], $resellerId);
        $creator = $this->getContractor(Employee::class, (int)$requestData['creatorId']);
        $expert = $this->getContractor(Employee::class, (int)$requestData['expertId']);

        $templateData = $this->prepareTemplateData($requestData, $client, $creator, $expert, $resellerId);

        return $this->sendNotifications($templateData, $resellerId, $client, $notificationType);
    }

    private function validateRequestData(array $data): void
    {
        $requiredFields = ['resellerId', 'notificationType', 'clientId', 'creatorId', 'expertId'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Field '{$field}' is required and cannot be empty");
            }
        }
    }

    private function getContractor(string $class, int $id, int $resellerId = null): Contractor
    {
        $contractor = $class::getById($id);
        if ($contractor === null || ($resellerId && $contractor->Seller->id !== $resellerId)) {
            throw new \RuntimeException('Contractor not found or invalid');
        }
        return $contractor;
    }

    private function prepareTemplateData(array $data, Contractor $client, Employee $creator, Employee $expert, int $resellerId): array
    {
        $differences = $this->defineDifferences($data, $resellerId);

        $templateData = [
            'CLIENT_NAME' => $client->getFullName(),
            'CREATOR_NAME' => $creator->getFullName(),
            'EXPERT_NAME' => $expert->getFullName(),
            'DIFFERENCES' => $differences,
        ];

        $commonDataKeys = [
            'complaintId', 'complaintNumber', 'creatorId', 'expertId', 'clientId', 'consumptionId',
            'consumptionNumber', 'agreementNumber', 'date'
        ];
        foreach ($commonDataKeys as $key) {
            if (empty($data[$key])) {
                throw new \InvalidArgumentException("Template Data for '{$key}' is empty!");
            }
            $templateData[strtoupper($key)] = $data[$key];
        }

        return $templateData;
    }

    private function defineDifferences(array $data, int $resellerId): string
    {
        if ($data['notificationType'] === self::TYPE_NEW) {
            return 'New position added';
        } elseif ($data['notificationType'] === self::TYPE_CHANGE && !empty($data['differences'])) {
            return 'Position status has changed from ' .
                Status::getName((int)$data['differences']['from']) .
                ' to ' .
                Status::getName((int)$data['differences']['to']);
        }

        return '';
    }


    private function sendNotifications(array $templateData, int $resellerId, Contractor $client, int $notificationType): array
    {
        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail' => false,
            'notificationClientBySms' => ['isSent' => false, 'message' => '']
        ];

        // Предполагаем, что EmailService и SmsService уже интегрированы и готовы к использованию.
        if ($notificationType === self::TYPE_CHANGE) {
            $emailSent = EmailService::send($client->email, "Status Change Notification", $templateData['DIFFERENCES']);
            $result['notificationClientByEmail'] = $emailSent;

            $smsSent = SmsService::send($client->mobile, "Your status has changed.");
            $result['notificationClientBySms'] = ['isSent' => $smsSent, 'message' => 'Your status has changed.'];
        }

        // Возможно, добавить условия для других типов уведомлений.

        return $result;
    }
}
