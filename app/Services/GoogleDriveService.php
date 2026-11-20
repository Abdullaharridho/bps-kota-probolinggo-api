<?php

namespace App\Services;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class GoogleDriveService
{
    private Drive $drive;

    public function __construct()
    {
        $client = new Client();

        $client->setApplicationName('Koproda');
        $client->setClientId(config('services.google_drive.client_id'));
        $client->setClientSecret(config('services.google_drive.client_secret'));

        $client->setScopes([
            Drive::DRIVE_FILE,
        ]);

        $client->setAccessType('offline');

        $refreshToken = config('services.google_drive.refresh_token');

        if (empty($refreshToken)) {
            throw new RuntimeException('Google Drive Refresh Token belum dikonfigurasi.');
        }

        // Minta Access Token baru menggunakan Refresh Token.
        $token = $client->fetchAccessTokenWithRefreshToken($refreshToken);

        if (isset($token['error'])) {
            throw new RuntimeException(
                'Gagal autentikasi Google Drive: ' .
                ($token['error_description'] ?? $token['error'])
            );
        }

        $client->setAccessToken($token);

        $this->drive = new Drive($client);
    }

    public function upload(UploadedFile $file): string
    {
        $metadata = new DriveFile([
            'name' => $file->getClientOriginalName(),
        ]);

        $folderId = config('services.google_drive.folder_id');

        if (!empty($folderId)) {
            $metadata->setParents([$folderId]);
        }

        $result = $this->drive->files->create(
            $metadata,
            [
                'data' => file_get_contents($file->getRealPath()),
                'mimeType' => $file->getMimeType(),
                'uploadType' => 'multipart',
                'fields' => 'id',
            ]
        );

        if (empty($result->id)) {
            throw new RuntimeException('Google Drive tidak mengembalikan File ID.');
        }

        return $result->id;
    }

    public function download(string $fileId)
    {
        if (empty($fileId)) {
            throw new RuntimeException('Google Drive File ID tidak boleh kosong.');
        }

        return $this->drive->files->get(
            $fileId,
            ['alt' => 'media']
        );
    }

    public function delete(string $fileId): void
    {
        if (empty($fileId)) {
            return;
        }

        $this->drive->files->delete($fileId);
    }
}
