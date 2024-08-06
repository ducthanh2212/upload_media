<?php

require __DIR__ . '/../vendor/autoload.php';

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;

// Thiết lập kết nối cơ sở dữ liệu MySQL
$host = 'localhost';
$db = 'test';
$user = 'root';
$pass = '';

$pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$bucket = 'localstack'; // Tên bucket đã được tạo trong LocalStack

// Các hàm hỗ trợ cho việc quản lý trạng thái upload trong cơ sở dữ liệu
function getUploadStatus($pdo, $keyname)
{
    $stmt = $pdo->prepare("SELECT * FROM multipart_upload_status WHERE key_name = ?");
    $stmt->execute([$keyname]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function saveUploadId($pdo, $keyname, $uploadId)
{
    $stmt = $pdo->prepare("INSERT INTO multipart_upload_status (key_name, upload_id, part_number, etag) VALUES (?, ?, 0, '')");
    $stmt->execute([$keyname, $uploadId]);
}

function savePartETag($pdo, $keyname, $partNumber, $etag)
{
    $stmt = $pdo->prepare("INSERT INTO multipart_upload_status (key_name, upload_id, part_number, etag) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE etag = VALUES(etag)");
    $stmt->execute([$keyname, getUploadId($pdo, $keyname), $partNumber, $etag]);
}

function deleteUploadStatus($pdo, $keyname)
{
    $stmt = $pdo->prepare("DELETE FROM multipart_upload_status WHERE key_name = ?");
    $stmt->execute([$keyname]);
}

function getUploadId($pdo, $keyname)
{
    $stmt = $pdo->prepare("SELECT upload_id FROM multipart_upload_status WHERE key_name = ? LIMIT 1");
    $stmt->execute([$keyname]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['upload_id'] : null;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['files'])) {
    $files = $_FILES['files'];

    // Tạo kết nối tới LocalStack S3
    $s3 = new S3Client([
        'version' => 'latest',
        'region'  => 'us-east-1',
        'endpoint' => 'http://localhost:4566', // Endpoint của LocalStack
        'use_path_style_endpoint' => true, // LocalStack yêu cầu sử dụng path style endpoint
        'credentials' => [
            'key'    => 'test', // AWS Access Key ID
            'secret' => 'test', // AWS Secret Access Key
        ]
    ]);

    // Lặp qua từng tệp được tải lên
    for ($i = 0; $i < count($files['name']); $i++) {
        $filename = $files['tmp_name'][$i]; // Đường dẫn tạm thời của tệp trên server
        $keyname = basename($files['name'][$i]); // Tên tệp trên S3 

        $uploadStatus = getUploadStatus($pdo, $keyname);
        $uploadId = getUploadId($pdo, $keyname);
        $parts = ['Parts' => []];

        if ($uploadId === null) {
            $result = $s3->createMultipartUpload([
                'Bucket'       => $bucket,
                'Key'          => $keyname,
                'StorageClass' => 'REDUCED_REDUNDANCY',
                'Metadata'     => [
                    'param1' => 'value 1', // Thay các giá trị này bằng các giá trị mong muốn
                    'param2' => 'value 2',
                    'param3' => 'value 3'
                ]
            ]);
            $uploadId = $result['UploadId'];
            saveUploadId($pdo, $keyname, $uploadId);
        } else {
            foreach ($uploadStatus as $status) {
                if ($status['part_number'] > 0) {
                    $parts['Parts'][$status['part_number']] = [
                        'PartNumber' => $status['part_number'],
                        'ETag' => $status['etag'],
                    ];
                }
            }
        }

        try {
            $file = fopen($filename, 'r');
            $partNumber = count($parts['Parts']) + 1;
            $partSize = 5 * 1024 * 1024; // 5 MB

            fseek($file, ($partNumber - 1) * $partSize);

            while (!feof($file)) {
                $result = $s3->uploadPart([
                    'Bucket'     => $bucket,
                    'Key'        => $keyname,
                    'UploadId'   => $uploadId,
                    'PartNumber' => $partNumber,
                    'Body'       => fread($file, $partSize),
                ]);
                $parts['Parts'][] = [
                    'PartNumber' => $partNumber,
                    'ETag' => $result['ETag'],
                ];
                savePartETag($pdo, $keyname, $partNumber, $result['ETag']);
                $partNumber++;

                echo "Uploading part $partNumber of $filename." . PHP_EOL;
            }
            fclose($file);
        } catch (S3Exception $e) {
            $result = $s3->abortMultipartUpload([
                'Bucket'   => $bucket,
                'Key'      => $keyname,
                'UploadId' => $uploadId
            ]);

            echo "Upload of $filename failed: " . $e->getMessage() . PHP_EOL;
            continue;
        }

        // Hoàn tất upload theo từng phần
        $result = $s3->completeMultipartUpload([
            'Bucket'   => $bucket,
            'Key'      => $keyname,
            'UploadId' => $uploadId,
            'MultipartUpload' => $parts,
        ]);
        $url = $result['Location'];

        // Xóa trạng thái upload từ cơ sở dữ liệu
        deleteUploadStatus($pdo, $keyname);

        // Hiển thị URL để xem hình ảnh
        echo "Uploaded $filename to $url." . PHP_EOL;
        echo "<a href='$url' target='_blank'>View Image</a>" . PHP_EOL;
    }
} else {
    echo "No files uploaded.";
}
