<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    $fileTmpName = $file['tmp_name'];
    $fileName = basename($file['name']);
    $fileSize = $file['size'];
    $fileError = $file['error'];
    $fileType = $file['type'];

    // Kiểm tra lỗi upload
    if ($fileError === UPLOAD_ERR_OK) {
        // Đọc nội dung tệp
        $fileContent = file_get_contents($fileTmpName);
        $fileBase64 = base64_encode($fileContent);

        echo "<h2>Uploaded Image</h2>";
        echo "<img src='data:$fileType;base64,$fileBase64' alt='Uploaded Image' style='max-width: 100%; height: auto;'>";
        echo "<p><a href='upload.html'>Upload another file</a></p>";
    } else {
        echo "Error uploading file.";
    }
} else {
    echo "No file uploaded.";
}
?>
