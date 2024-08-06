CREATE TABLE multipart_upload_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_name VARCHAR(255) NOT NULL,
    upload_id VARCHAR(255) NOT NULL,
    part_number INT NOT NULL,
    etag VARCHAR(255) NOT NULL,
    UNIQUE KEY unique_key_part (key_name, part_number)
);


require 'vendor/autoload.php';
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;

// Thiết lập kết nối cơ sở dữ liệu MySQL
$host = 'localhost';
$db = 'yourdatabase';
$user = 'yourusername';
$pass = 'yourpassword';

$pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$bucket = '*** Your Bucket Name ***';
$keyname = '*** Your Object Key ***';
$filename = '*** Path to and Name of the File to Upload ***';

$s3 = new S3Client([
    'version' => 'latest',
    'region'  => 'us-east-1'
]);

// Kiểm tra trạng thái upload từ cơ sở dữ liệu
function getUploadStatus($pdo, $keyname) {
    $stmt = $pdo->prepare("SELECT * FROM multipart_upload_status WHERE key_name = ?");
    $stmt->execute([$keyname]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function saveUploadId($pdo, $keyname, $uploadId) {
    $stmt = $pdo->prepare("INSERT INTO multipart_upload_status (key_name, upload_id, part_number, etag) VALUES (?, ?, 0, '')");
    $stmt->execute([$keyname, $uploadId]);
}

function savePartETag($pdo, $keyname, $partNumber, $etag) {
    $stmt = $pdo->prepare("INSERT INTO multipart_upload_status (key_name, upload_id, part_number, etag) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE etag = VALUES(etag)");
    $stmt->execute([$keyname, getUploadId($pdo, $keyname), $partNumber, $etag]);
}

function deleteUploadStatus($pdo, $keyname) {
    $stmt = $pdo->prepare("DELETE FROM multipart_upload_status WHERE key_name = ?");
    $stmt->execute([$keyname]);
}

function getUploadId($pdo, $keyname) {
    $stmt = $pdo->prepare("SELECT upload_id FROM multipart_upload_status WHERE key_name = ? LIMIT 1");
    $stmt->execute([$keyname]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['upload_id'] : null;
}

$uploadStatus = getUploadStatus($pdo, $keyname);
$uploadId = getUploadId($pdo, $keyname);
$parts = ['Parts' => []];

if ($uploadId === null) {
    $result = $s3->createMultipartUpload([
        'Bucket'       => $bucket,
        'Key'          => $keyname,
        'StorageClass' => 'REDUCED_REDUNDANCY',
        'Metadata'     => [
            'param1' => 'value 1', // thay các giá trị này bằng các giá trị mong muốn ví dụ như là tên người chụp: Thanh, ....
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
        $parts['Parts'][$partNumber] = [
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

    echo "Upload of $filename failed." . PHP_EOL;
}

// Complete the multipart upload.
$result = $s3->completeMultipartUpload([
    'Bucket'   => $bucket,
    'Key'      => $keyname,
    'UploadId' => $uploadId,
    'MultipartUpload'    => $parts,
]);
$url = $result['Location'];

// Xóa trạng thái upload từ cơ sở dữ liệu
deleteUploadStatus($pdo, $keyname);

echo "Uploaded $filename to $url." . PHP_EOL;
