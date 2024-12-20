<?php
// Kết nối database
$host = 'localhost';
$dbname = 'yiawatoehosting_lich';
$user = 'yiawatoehosting_lich';
$password = 'PHPphp22@@';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $user, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Lỗi kết nối database: " . $e->getMessage());
}

// Cấu hình Telegram Bot
$botToken = "7613702948:AAG0thUWVtYjhtY1ys3zl7GCWamqbWYur4c";
$chatIDs = [
    "-4664778173",      // Group 1
  //   "-670693645",       // Group 2
 //    "-1002440526082"    // Group 3
];

// Hàm lấy dữ liệu lịch hẹn tuần tới
function getNextWeekAppointments($conn) {
    $nextWeekStart = date('Y-m-d', strtotime('next monday'));
    $nextWeekEnd = date('Y-m-d', strtotime('next sunday'));

    $sql = "SELECT * FROM appointments 
            WHERE date BETWEEN :start_date AND :end_date 
            ORDER BY date ASC, start_time ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':start_date', $nextWeekStart);
    $stmt->bindParam(':end_date', $nextWeekEnd);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Hàm format tin nhắn Telegram
function formatTelegramMessage($appointments) {
    $message = "Xin phép thầy Phùng Phương và cả nhóm chúng ta.\n";
    $message .= "Tôi xin tổng kết lại lịch trình của thầy trong tuần tới tính đến 12h ngày hôm nay:\n\n";

    $nextWeekStart = date('Y-m-d', strtotime('next monday'));
    $weekDays = [];

    for ($i = 0; $i < 7; $i++) {
        $date = date('Y-m-d', strtotime($nextWeekStart . " +$i days"));
        $weekDays[$date] = [];
    }

    foreach ($appointments as $appointment) {
        $weekDays[$appointment['date']][] = $appointment;
    }

    foreach ($weekDays as $date => $dayAppointments) {
        $dateObj = new DateTime($date);
        $dayOfWeek = $dateObj->format('l');
        $formattedDate = $dateObj->format('d/m/Y');

        switch ($dayOfWeek) {
            case 'Monday': $dayOfWeekVN = 'Thứ 2'; break;
            case 'Tuesday': $dayOfWeekVN = 'Thứ 3'; break;
            case 'Wednesday': $dayOfWeekVN = 'Thứ 4'; break;
            case 'Thursday': $dayOfWeekVN = 'Thứ 5'; break;
            case 'Friday': $dayOfWeekVN = 'Thứ 6'; break;
            case 'Saturday': $dayOfWeekVN = 'Thứ 7'; break;
            case 'Sunday': $dayOfWeekVN = 'Chủ Nhật'; break;
        }

        $message .= "📅 $dayOfWeekVN - $formattedDate:\n";

        if (empty($dayAppointments)) {
            $message .= "Không có lịch hẹn\n";
        } else {
            foreach ($dayAppointments as $apt) {
                $message .= "⏰ {$apt['start_time']} - {$apt['end_time']}\n";
                $message .= "👤 Người đặt: {$apt['name']}\n";
                if (!empty($apt['notes'])) {
                    $message .= "📝 Ghi chú: {$apt['notes']}\n";
                }
                if (!empty($apt['location'])) {
                    $message .= "📍 Địa điểm: {$apt['location']}\n";
                }
                $message .= "-------------------\n";
            }
        }
        $message .= "\n";
    }

    return $message;
}

// Hàm gửi nhắc nhở
function sendReminder($botToken, $chatIDs) {
    $dayOfWeek = date('l');
    switch ($dayOfWeek) {
        case 'Monday': $dayOfWeekVN = 'Thứ 2'; break;
        case 'Tuesday': $dayOfWeekVN = 'Thứ 3'; break;
        case 'Wednesday': $dayOfWeekVN = 'Thứ 4'; break;
        case 'Thursday': $dayOfWeekVN = 'Thứ 5'; break;
        case 'Friday': $dayOfWeekVN = 'Thứ 6'; break;
        case 'Saturday': $dayOfWeekVN = 'Thứ 7'; break;
        case 'Sunday': $dayOfWeekVN = 'Chủ Nhật'; break;
    }

    $message = "Hôm nay đã là {$dayOfWeekVN} nhưng tôi chưa nhận được lịch.\n";
    $message .= "Yêu cầu các bạn đặt lịch tại link: lich.phunggia.company\n\n";
    $message .= "Thầy rất bận rộn nên các bạn hãy đặt lịch sớm để giúp thầy sắp xếp lịch trình hợp lý hơn với tất cả công việc các bạn nhé !!!";

    $success = true;
    $errors = [];

    foreach ($chatIDs as $chatID) {
        $telegramAPI = "https://api.telegram.org/bot" . $botToken . "/sendMessage";
        $postData = array(
            'chat_id' => $chatID,
            'text' => $message,
            'parse_mode' => 'HTML'
        );

        $ch = curl_init($telegramAPI);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $success = false;
            $errors[] = 'Lỗi gửi đến group ' . $chatID . ': ' . curl_error($ch);
        }
        curl_close($ch);
        sleep(1); // Dừng 1 giây giữa mỗi lần gửi để tránh bị Telegram chặn
    }

    return ['success' => $success, 'errors' => $errors];
}

// Xử lý các yêu cầu POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['check_schedule'])) {
        // Kiểm tra lịch tuần tới
        $appointments = getNextWeekAppointments($conn);
        $message = formatTelegramMessage($appointments);
        
    } elseif (isset($_POST['send_notification'])) {
        // Gửi thông báo lịch tuần tới
        $appointments = getNextWeekAppointments($conn);
        $message = formatTelegramMessage($appointments);
        
        $success = true;
        $errors = [];

        foreach ($chatIDs as $chatID) {
            $telegramAPI = "https://api.telegram.org/bot" . $botToken . "/sendMessage";
            $postData = array(
                'chat_id' => $chatID,
                'text' => $message,
                'parse_mode' => 'HTML'
            );

            $ch = curl_init($telegramAPI);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                $success = false;
                $errors[] = 'Lỗi gửi đến group ' . $chatID . ': ' . curl_error($ch);
            }
            curl_close($ch);
            sleep(1);
        }
    } elseif (isset($_POST['send_reminder'])) {
        // Gửi nhắc nhở
        $reminderResult = sendReminder($botToken, $chatIDs);
        $success = $reminderResult['success'];
        $errors = $reminderResult['errors'];
    } elseif (isset($_POST['submit_appointment'])) {
        // Xử lý đặt lịch
        $date = $_POST['date'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $notes = $_POST['notes'];
        $name = $_POST['name'];
        $location = $_POST['location'];
        $related_people = $_POST['related_people'];
        $type = $_POST['type'];

        if (strtotime($end_time) <= strtotime($start_time)) {
            $error = "Giờ kết thúc phải sau giờ bắt đầu!";
        } else {
            // Kiểm tra xem có lịch trùng không
            $sql_check = "SELECT * FROM appointments WHERE date = :date AND ((start_time <= :end_time AND end_time >= :start_time))";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->bindParam(':date', $date);
            $stmt_check->bindParam(':start_time', $start_time);
            $stmt_check->bindParam(':end_time', $end_time);
            $stmt_check->execute();
            $check = $stmt_check->fetch(PDO::FETCH_ASSOC);
            if ($check) {
                $error = "Trùng lịch, đặt lại lịch nhé!";
            } else {
                try {
                    $sql = "INSERT INTO appointments (date, start_time, end_time, notes, name, location, related_people, type)
                          VALUES (:date, :start_time, :end_time, :notes, :name, :location, :related_people, :type)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bindParam(':date', $date);
                    $stmt->bindParam(':start_time', $start_time);
                    $stmt->bindParam(':end_time', $end_time);
                    $stmt->bindParam(':notes', $notes);
                    $stmt->bindParam(':name', $name);
                    $stmt->bindParam(':location', $location);
                    $stmt->bindParam(':related_people', $related_people);
                    $stmt->bindParam(':type', $type);
                    $stmt->execute();   
                
          

                    // Gửi thông báo đến Telegram
                    $botToken = "7613702948:AAG0thUWVtYjhtY1ys3zl7GCWamqbWYur4c"; // Thay bằng token của bot
                    $chatIDs = ["-4664778173", "-670693645", "-1002440526082"]; // Danh sách chat ID của các nhóm

                    // Định dạng lại nội dung tin nhắn
                    $message = "Thưa thầy Phùng Phương, vừa có lịch được đặt:\n\n";

                    // Định dạng ngày tháng
                    $date_obj = new DateTime($date);
                    $dayOfWeek = $date_obj->format('l'); // Lấy thứ trong tuần (ví dụ: Monday, Tuesday)
                    $formattedDate = $date_obj->format('d/m/Y'); // Lấy ngày tháng năm

                    // Chuyển đổi thứ sang tiếng Việt
                    $dayOfWeekVietnamese = '';
                    switch ($dayOfWeek) {
                        case 'Monday':
                            $dayOfWeekVietnamese = 'Thứ 2';
                            break;
                        case 'Tuesday':
                            $dayOfWeekVietnamese = 'Thứ 3';
                            break;
                        case 'Wednesday':
                            $dayOfWeekVietnamese = 'Thứ 4';
                            break;
                        case 'Thursday':
                            $dayOfWeekVietnamese = 'Thứ 5';
                            break;
                        case 'Friday':
                            $dayOfWeekVietnamese = 'Thứ 6';
                            break;
                        case 'Saturday':
                            $dayOfWeekVietnamese = 'Thứ 7';
                            break;
                        case 'Sunday':
                            $dayOfWeekVietnamese = 'Chủ Nhật';
                            break;
                    }

                    $message .= "📅 " . $dayOfWeekVietnamese . " - " . $formattedDate . " ⏰ Khung giờ: " . $start_time . " - " . $end_time . "\n";
                    $message .= "👤 Người đặt: " . $name . "\n";
                    $message .= "👤👤 Người liên quan: " . $related_people . "\n";
                    $message .= "📝 Ghi chú: " . $notes . "\n";
                    $message .= "📍 Địa điểm: " . $location . "\n\n";
                    $message .= "Xin thầy xác nhận!";

                    foreach ($chatIDs as $chatID) {
                        $telegramAPI = "https://api.telegram.org/bot" . $botToken . "/sendMessage?chat_id=" . $chatID . "&text=" . urlencode($message);

                        try {
                            $response = file_get_contents($telegramAPI);
                            if ($response === FALSE) {
                                throw new Exception("Lỗi khi gửi thông báo Telegram đến nhóm " . $chatID);
                            }
                        } catch (Exception $e) {
                            // Xử lý lỗi, ví dụ: ghi log lỗi
                            error_log("Lỗi gửi thông báo Telegram: " . $e->getMessage()); // Ghi log lỗi
                        }
                    }
                    // --- Kết thúc phần gửi thông báo Telegram ---
                    echo "<script>alert('Đặt lịch thành công!');</script>";
                    echo "<script>window.location.href = window.location.href;</script>";
                    exit(); // Dừng thực thi script sau khi đã xử lý xong form
                } catch (PDOException $e) {
                    $error = "Lỗi: " . $e->getMessage();
                }
            }
        }
    } elseif (isset($_GET['delete'])) {
        $id = $_GET['delete'];
        try {
            $sql = "DELETE FROM appointments WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':id', $id);
            $stmt->execute();

            // Gửi thông báo xóa lịch đến Telegram
            $botToken = "7613702948:AAG0thUWVtYjhtY1ys3zl7GCWamqbWYur4c"; // Thay bằng token của bot
            $chatIDs = ["-4664778173", "-670693645", "-1002440526082"]; // Danh sách chat ID của các nhóm

            // Lấy thông tin lịch bị xóa
            $deletedAppointmentSql = "SELECT * FROM appointments WHERE id = :id";
            $deletedAppointmentStmt = $conn->prepare($deletedAppointmentSql);
            $deletedAppointmentStmt->bindParam(':id', $id);
            $deletedAppointmentStmt->execute();
            $deletedAppointment = $deletedAppointmentStmt->fetch(PDO::FETCH_ASSOC);

            if ($deletedAppointment) {
                // Định dạng ngày tháng cho lịch bị xóa
                $deletedDateObj = new DateTime($deletedAppointment['date']);
                $deletedDayOfWeek = $deletedDateObj->format('l');
                $deletedFormattedDate = $deletedDateObj->format('d/m/Y');
                $deletedDayOfWeekVietnamese = '';
                switch ($deletedDayOfWeek) {
                    case 'Monday': $deletedDayOfWeekVietnamese = 'Thứ 2'; break;
                    case 'Tuesday': $deletedDayOfWeekVietnamese = 'Thứ 3'; break;
                    case 'Wednesday': $deletedDayOfWeekVietnamese = 'Thứ 4'; break;
                    case 'Thursday': $deletedDayOfWeekVietnamese = 'Thứ 5'; break;
                    case 'Friday': $deletedDayOfWeekVietnamese = 'Thứ 6'; break;
                    case 'Saturday': $deletedDayOfWeekVietnamese = 'Thứ 7'; break;
                    case 'Sunday': $deletedDayOfWeekVietnamese = 'Chủ Nhật'; break;
                }

                $deletedMessage = "Thưa thầy Phùng Phương,\n";
                $deletedMessage .= "Vừa có yêu cầu **xóa lịch**:\n\n";
                $deletedMessage .= "**Lịch bị xóa:**\n";
                $deletedMessage .= "📅 " . $deletedDayOfWeekVietnamese . " - " . $deletedFormattedDate . " ⏰ Khung giờ: " . $deletedAppointment['start_time'] . " - " . $deletedAppointment['end_time'] . "\n";
                $deletedMessage .= "👤 Người đặt: " . $deletedAppointment['name'] . "\n";
                $deletedMessage .= "👤👤 Người liên quan: " . $deletedAppointment['related_people'] . "\n";
                $deletedMessage .= "📝 Ghi chú: " . $deletedAppointment['notes'] . "\n";
                $deletedMessage .= "📍 Địa điểm: " . $deletedAppointment['location'] . "\n";

                foreach ($chatIDs as $chatID) {
                    $telegramAPI = "https://api.telegram.org/bot" . $botToken . "/sendMessage?chat_id=" . $chatID . "&text=" . urlencode($deletedMessage);
                    try {
                        $response = file_get_contents($telegramAPI);
                        if ($response === FALSE) {
                            throw new Exception("Lỗi khi gửi thông báo xóa lịch đến nhóm " . $chatID);
                        }
                    } catch (Exception $e) {
                        error_log("Lỗi gửi thông báo xóa lịch: " . $e->getMessage());
                    }
                }
            }
            echo "<script>alert('Xóa lịch thành công!');</script>";
            echo "<script>window.location.href = window.location.href;</script>";  
        } catch (PDOException $e) {
            $error = "Lỗi: " . $e->getMessage();
        }
    } elseif (isset($_POST['update_appointment'])) {
        // Xử lý cập nhật lịch hẹn
        $update_id = $_POST['update_id'];
        $update_date = $_POST['update_date'];
        $update_start_time = $_POST['update_start_time'];
        $update_end_time = $_POST['update_end_time'];
        $update_notes = $_POST['update_notes'];
        $update_location = $_POST['update_location'];
        $update_related_people = $_POST['update_related_people'];
        $update_type = $_POST['update_type'];

        // Check if the end time is greater than the start time
        if (strtotime($update_end_time) <= strtotime($update_start_time)) {
            echo "<script>alert('Giờ kết thúc phải sau giờ bắt đầu!');</script>";
        } else {
            // Check for overlapping appointments
            $sql_check = "SELECT * FROM appointments WHERE id != :id AND date = :date AND ((start_time < :end_time AND end_time > :start_time) OR (start_time = :start_time AND end_time = :end_time))";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->bindParam(':id', $update_id);
            $stmt_check->bindParam(':date', $update_date);
            $stmt_check->bindParam(':start_time', $update_start_time);
            $stmt_check->bindParam(':end_time', $update_end_time);
            $stmt_check->execute();
            $check = $stmt_check->fetch(PDO::FETCH_ASSOC);
            if ($check) {
                echo "<script>alert('Trùng lịch, đặt lại lịch nhé!');</script>";
            } else {
                try {
                    $sql_update = "UPDATE appointments SET date = :date, start_time = :start_time, end_time = :end_time, notes = :notes, location = :location, related_people = :related_people, type = :type WHERE id = :id";
                    $stmt_update = $conn->prepare($sql_update);
                    $stmt_update->bindParam(':id', $update_id);
                    $stmt_update->bindParam(':date', $update_date);
                    $stmt_update->bindParam(':start_time', $update_start_time);
                    $stmt_update->bindParam(':end_time', $update_end_time);
                    $stmt_update->bindParam(':notes', $update_notes);
                    $stmt_update->bindParam(':location', $update_location);
                    $stmt_update->bindParam(':related_people', $update_related_people);
                    $stmt_update->bindParam(':type', $update_type);
                    $stmt_update->execute();
                    // Gửi thông báo sửa lịch đến Telegram
                    $botToken = "7613702948:AAG0thUWVtYjhtY1ys3zl7GCWamqbWYur4c"; // Thay bằng token của bot
                    $chatIDs = ["-4664778173", "-670693645", "-1002440526082"]; // Danh sách chat ID của các nhóm

                    // Lấy thông tin lịch trước khi sửa
                    $originalAppointmentSql = "SELECT * FROM appointments WHERE id = :id";
                    $originalAppointmentStmt = $conn->prepare($originalAppointmentSql);
                    $originalAppointmentStmt->bindParam(':id', $update_id);
                    $originalAppointmentStmt->execute();
                    $originalAppointment = $originalAppointmentStmt->fetch(PDO::FETCH_ASSOC);

                    // Định dạng ngày tháng cho lịch cũ
                    $originalDateObj = new DateTime($originalAppointment['date']);
                    $originalDayOfWeek = $originalDateObj->format('l');
                    $originalFormattedDate = $originalDateObj->format('d/m/Y');
                    $originalDayOfWeekVietnamese = '';
                    switch ($originalDayOfWeek) {
                        case 'Monday': $originalDayOfWeekVietnamese = 'Thứ 2'; break;
                        case 'Tuesday': $originalDayOfWeekVietnamese = 'Thứ 3'; break;
                        case 'Wednesday': $originalDayOfWeekVietnamese = 'Thứ 4'; break;
                        case 'Thursday': $originalDayOfWeekVietnamese = 'Thứ 5'; break;
                        case 'Friday': $originalDayOfWeekVietnamese = 'Thứ 6'; break;
                        case 'Saturday': $originalDayOfWeekVietnamese = 'Thứ 7'; break;
                        case 'Sunday': $originalDayOfWeekVietnamese = 'Chủ Nhật'; break;
                    }

                    // Định dạng ngày tháng cho lịch mới
                    $newDateObj = new DateTime($update_date);
                    $newDayOfWeek = $newDateObj->format('l');
                    $newFormattedDate = $newDateObj->format('d/m/Y');
                    $newDayOfWeekVietnamese = '';
                    switch ($newDayOfWeek) {
                        case 'Monday': $newDayOfWeekVietnamese = 'Thứ 2'; break;
                        case 'Tuesday': $newDayOfWeekVietnamese = 'Thứ 3'; break;
                        case 'Wednesday': $newDayOfWeekVietnamese = 'Thứ 4'; break;
                        case 'Thursday': $newDayOfWeekVietnamese = 'Thứ 5'; break;
                        case 'Friday': $newDayOfWeekVietnamese = 'Thứ 6'; break;
                        case 'Saturday': $newDayOfWeekVietnamese = 'Thứ 7'; break;
                        case 'Sunday': $newDayOfWeekVietnamese = 'Chủ Nhật'; break;
                    }

                    $updateMessage = "Thưa thầy Phùng Phương,\n";
                    $updateMessage .= "Vừa có yêu cầu **thay đổi lịch**:\n\n";
                    $updateMessage .= "**Lịch cũ:**\n";
                    $updateMessage .= "📅 " . $originalDayOfWeekVietnamese . " - " . $originalFormattedDate . " ⏰ Khung giờ: " . $originalAppointment['start_time'] . " - " . $originalAppointment['end_time'] . "\n";
                    $updateMessage .= "👤 Người đặt: " . $originalAppointment['name'] . "\n";
                    $updateMessage .= "👤👤 Người liên quan: " . $originalAppointment['related_people'] . "\n";
                    $updateMessage .= "📝 Ghi chú: " . $originalAppointment['notes'] . "\n";
                    $updateMessage .= "📍 Địa điểm: " . $originalAppointment['location'] . "\n\n";
                    $updateMessage .= "**Lịch được đổi sang:**\n";
                    $updateMessage .= "📅 " . $newDayOfWeekVietnamese . " - " . $newFormattedDate . " ⏰ Khung giờ: " . $update_start_time . " - " . $update_end_time . "\n";
                    $updateMessage .= "👤 Người đặt: " . $originalAppointment['name'] . "\n"; // Người đặt vẫn giữ nguyên
                    $updateMessage .= "👤👤 Người liên quan: " . $update_related_people . "\n";
                    $updateMessage .= "📝 Ghi chú: " . $update_notes . "\n";
                    $updateMessage .= "📍 Địa điểm: " . $update_location . "\n";

                    foreach ($chatIDs as $chatID) {
                        $telegramAPI = "https://api.telegram.org/bot" . $botToken . "/sendMessage?chat_id=" . $chatID . "&text=" . urlencode($updateMessage);
                        try {
                            $response = file_get_contents($telegramAPI);
                            if ($response === FALSE) {
                                throw new Exception("Lỗi khi gửi thông báo sửa lịch đến nhóm " . $chatID);
                            }
                        } catch (Exception $e) {
                            error_log("Lỗi gửi thông báo sửa lịch: " . $e->getMessage());
                        }
                    }
                    echo "<script>alert('Cập nhật lịch thành công!'); window.location.href = window.location.href;</script>";
                    exit();
                } catch (PDOException $e) {
                    $error = "Lỗi: " . $e->getMessage();
                }
            }
        }
    }
}

// Lấy danh sách lịch sử thao tác
function getHistory($conn) {
    $sql = "SELECT * FROM history ORDER BY timestamp DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Lấy danh sách thông báo Telegram
function getTelegramNotifications($conn) {
    $sql = "SELECT * FROM telegram_notifications ORDER BY sent_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Xử lý xác thực mật khẩu cho phần "Cài đặt"
$showSettings = false;
if (isset($_POST['password']) && $_POST['password'] === '222222') {
    $showSettings = true;
}
function convertUrlsToLinks($text) {
  // Biểu thức chính quy để tìm URL bắt đầu bằng http:// hoặc https://
  $pattern = '#\b(https?://\S+)#i';

  // Thay thế các URL tìm thấy bằng thẻ <a>
  $text = preg_replace_callback($pattern, function ($matches) {
    $url = $matches[1];
    // Thêm target="_blank" để mở liên kết trong tab mới
    return '<a href="' . htmlspecialchars($url) . '" target="_blank">' . htmlspecialchars($url) . '</a>';
  }, $text);

  return $text;
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đặt Lịch MPP</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/vi.js"></script> 
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <div class="logo">
                <img src="logo.jpg" alt="Logo">
                <h1>Đặt Lịch MPP</h1>
            </div>
            <ul class="menu">
                <li><a href="#" id="weekly-schedule-btn">Lịch tuần</a></li>
                <li><a href="#" id="add-appointment-btn">Đặt lịch mới</a></li>
                <li><a href="#" id="history-btn">Lịch sử</a></li>
                <li><a href="#" id="notifications-btn">Thông báo</a></li>
                <li><a href="#" id="settings-btn">Cài đặt</a></li>
            </ul>
        </div>
        <div class="main-content">
            <div class="tabs">
                <button class="tab active" data-target="add-appointment">Đặt lịch</button>
                <button class="tab" data-target="current-schedule">Lịch hiện tại</button>
                <button class="tab" data-target="past-schedule">Lịch cũ</button>
            </div>
            <div class="tab-content active" id="add-appointment">
                <h2>Đặt lịch hẹn</h2>
                <?php if(isset($error)): ?>
                    <p class="error"><?php echo $error; ?></p>
                <?php endif; ?>
                <form method="post" onsubmit="return validateTime();">
                    <div class="form-group">
                        <label for="date">Ngày:</label>
                        <input type="text" id="date" name="date" required>
                    </div>
                    <div class="form-group">
                        <label for="start_time">Giờ bắt đầu:</label>
                        <select id="start_time" name="start_time">
                            <?php
                            for ($i = 0; $i < 24; $i++) {
                                for ($j = 0; $j < 2; $j++) {
                                    $hour = str_pad($i, 2, '0', STR_PAD_LEFT);
                                    $minute = str_pad($j * 30, 2, '0', STR_PAD_LEFT);
                                    $time = $hour . ':' . $minute;
                                    echo "<option value=\"$time\">$time</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="end_time">Giờ kết thúc:</label>
                        <select id="end_time" name="end_time">
                            <?php
                            for ($i = 0; $i < 24; $i++) {
                                for ($j = 0; $j < 2; $j++) {
                                    $hour = str_pad($i, 2, '0', STR_PAD_LEFT);
                                    $minute = str_pad($j * 30, 2, '0', STR_PAD_LEFT);
                                    $time = $hour . ':' . $minute;
                                    echo "<option value=\"$time\">$time</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="name">Người đặt lịch:</label>
                        <input type="text" id="name" name="name" required>
                    </div>
                    <div class="form-group">
                        <label for="location">Địa điểm:</label>
                        <input type="text" id="location" name="location">
                    </div>
                    <div class="form-group">
                        <label for="related_people">Người liên quan:</label>
                        <input type="text" id="related_people" name="related_people" placeholder="Những người liên quan">
                    </div>
                    <div class="form-group">
                        <label for="type">Loại công việc:</label>
                        <select id="type" name="type">
                            <option value="0. TẬP TRUNG CÔNG VIỆC - HẠN CHẾ LIÊN HỆ">0. TẬP TRUNG CÔNG VIỆC - HẠN CHẾ LIÊN HỆ</option>
                            <option value="1. PG MEETING">1. PG MEETING</option>
                            <option value="2. LIVESTREAM / QUAY CHỤP">2. LIVESTREAM / QUAY CHỤP</option>
                            <option value="3. WORKSHOP / GIẢNG DẠY">3. WORKSHOP / GIẢNG DẠY</option>
                            <option value="4. THỰC ĐỊA">4. THỰC ĐỊA</option>
                            <option value="5. ĐỐI TÁC RIÊNG">5. ĐỐI TÁC RIÊNG</option>
                            <option value="6. RIÊNG TƯ">6. RIÊNG TƯ</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="notes">Ghi chú:</label>
                        <textarea id="notes" name="notes"></textarea>
                    </div>
                    <button type="submit" name="submit_appointment">Gửi yêu cầu</button>
                </form>
            </div>
            <div class="tab-content" id="current-schedule">
                <h2>Lịch hiện tại</h2>
                <?php
                    $whereClauses = [];
                    $params = [];
                    $whereClauses[] = "date >= CURDATE()"; // Lấy các lịch hẹn từ ngày hiện tại trở đi
                    $whereClause = "";
                    if (!empty($whereClauses)) {
                        $whereClause = "WHERE " . implode(" AND ", $whereClauses);
                    }
                
                    // Sắp xếp theo thời gian
                    $orderByClause = "ORDER BY date, start_time ASC";
                
                    // Lấy dữ liệu lịch trình từ database
                    $sql = "SELECT * FROM appointments $whereClause $orderByClause";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute($params);
                    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($appointments) > 0): ?>
                    <table class='schedule-table'>
                                                <thead>
                            <tr>
                                <th>Ngày</th>
                                <th>Giờ bắt đầu</th>
                                <th>Giờ kết thúc</th>
                                <th>Ghi chú</th>
                                <th>Địa điểm</th>
                                <th>Người liên quan</th>
                                <th>Loại công việc</th>
                                <th>Người đặt</th>
                                <th>Hành động</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $currentWeek = "";
                            foreach ($appointments as $appointment):
                                $date = new DateTime($appointment['date']);
                                $week = $date->format("W");
                                $dayOfWeek = $date->format('l');
                                $formattedDate = $date->format('d/m/Y');
                                $dayOfWeekVietnamese = '';
                                switch ($dayOfWeek) {
                                    case 'Monday': $dayOfWeekVietnamese = 'Thứ 2'; break;
                                    case 'Tuesday': $dayOfWeekVietnamese = 'Thứ 3'; break;
                                    case 'Wednesday': $dayOfWeekVietnamese = 'Thứ 4'; break;
                                    case 'Thursday': $dayOfWeekVietnamese = 'Thứ 5'; break;
                                    case 'Friday': $dayOfWeekVietnamese = 'Thứ 6'; break;
                                    case 'Saturday': $dayOfWeekVietnamese = 'Thứ 7'; break;
                                    case 'Sunday': $dayOfWeekVietnamese = 'Chủ Nhật'; break;
                                }

                                if ($week != $currentWeek):
                                    $currentWeek = $week;
                            ?>
                                    <tr class='week-separator'>
                                        <td colspan='9'>Tuần <?php echo $currentWeek; ?></td>
                                    </tr>
                                <?php endif; ?>
                                <tr>
                                    <td><?php echo $dayOfWeekVietnamese . ' - ' . $formattedDate; ?></td>
                                    <td><?php echo $appointment['start_time']; ?></td>
                                    <td><?php echo $appointment['end_time']; ?></td>
                                    <td><?php echo convertUrlsToLinks($appointment['notes']); ?></td>
                                    <td><?php echo $appointment['location']; ?></td>
                                    <td><?php echo $appointment['related_people']; ?></td>
                                    <td><?php echo $appointment['type']; ?></td>
                                    <td><?php echo $appointment['name']; ?></td>
                                    <td style='position: relative;'>
                                      <button class='edit-button' onclick='showEditPopup(<?php echo $appointment["id"]; ?>)'>Sửa</button>
                                      <a href='?delete=<?php echo $appointment["id"]; ?>' onclick='return confirm("Bạn có chắc chắn muốn xóa lịch hẹn này?") && showDeleteTooltip(<?php echo $appointment["id"]; ?>)'>Xóa</a>
                                      <div class='tooltip-container'>
                                      <span class='tooltip' id='deleteTooltip<?php echo $appointment["id"]; ?>'>Xóa lịch hẹn thành công!</span>
                                      </div>
                                  </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>Không có lịch hẹn nào.</p>
                <?php endif; ?>
            </div>
            <div class="tab-content" id="past-schedule">
                <h2>Lịch cũ</h2>
                <?php
                    $twoWeeksAgo = date('Y-m-d', strtotime('-2 weeks'));
                    $whereClauses = [];
                    $params = [];
                    $whereClauses[] = "date < CURDATE()"; // Lấy các lịch hẹn trước ngày hiện tại
                    
                    // Thêm điều kiện lọc ngày nếu cần
                    if (isset($_GET['filter_date_past']) && !empty($_GET['filter_date_past'])) {
                        $whereClauses[] = "date = :filter_date_past";
                        $params[':filter_date_past'] = $_GET['filter_date_past'];
                    }
                    
                    $whereClause = "";
                    if (!empty($whereClauses)) {
                        $whereClause = "WHERE " . implode(" AND ", $whereClauses);
                    }
                
                    // Sắp xếp theo thời gian
                    $orderByClause = "ORDER BY date DESC, start_time DESC";
                
                    // Lấy dữ liệu lịch trình từ database
                    $sql = "SELECT * FROM appointments $whereClause $orderByClause";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute($params);
                    $pastAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (count(array_filter($pastAppointments, function($appointment) use ($twoWeeksAgo) { return $appointment['date'] >= $twoWeeksAgo; })) > 0): ?>
                 <p>Lịch 2 tuần gần đây:</p>
                    <table class='schedule-table'>
                        <thead>
                            <tr>
                                <th>Ngày</th>
                                <th>Giờ bắt đầu</th>
                                <th>Giờ kết thúc</th>
                                <th>Ghi chú</th>
                                <th>Địa điểm</th>
                                <th>Người liên quan</th>
                                <th>Loại công việc</th>
                                <th>Người đặt</th>
                                 <th>Hành động</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($pastAppointments as $appointment):
                             if ($appointment['date'] >= $twoWeeksAgo) :
                                $date = new DateTime($appointment['date']);
                                $dayOfWeek = $date->format('l');
                                $formattedDate = $date->format('d/m/Y');
                                $dayOfWeekVietnamese = '';
                                switch ($dayOfWeek) {
                                    case 'Monday': $dayOfWeekVietnamese = 'Thứ 2'; break;
                                    case 'Tuesday': $dayOfWeekVietnamese = 'Thứ 3'; break;
                                    case 'Wednesday': $dayOfWeekVietnamese = 'Thứ 4'; break;
                                    case 'Thursday': $dayOfWeekVietnamese = 'Thứ 5'; break;
                                    case 'Friday': $dayOfWeekVietnamese = 'Thứ 6'; break;
                                    case 'Saturday': $dayOfWeekVietnamese = 'Thứ 7'; break;
                                    case 'Sunday': $dayOfWeekVietnamese = 'Chủ Nhật'; break;
                                }
                            ?>
                                <tr>
                                    <td><?php echo $dayOfWeekVietnamese . ' - ' . $formattedDate; ?></td>
                                    <td><?php echo $appointment['start_time']; ?></td>
                                    <td><?php echo $appointment['end_time']; ?></td>
                                     <td><?php echo convertUrlsToLinks($appointment['notes']); ?></td>
                                    <td><?php echo $appointment['location']; ?></td>
                                    <td><?php echo $appointment['related_people']; ?></td>
                                    <td><?php echo $appointment['type']; ?></td>
                                    <td><?php echo $appointment['name']; ?></td>
                                     <td style='position: relative;'>
                                      <button class='edit-button' onclick='showEditPopup(<?php echo $appointment["id"]; ?>)'>Sửa</button>
                                      <a href='?delete=<?php echo $appointment["id"]; ?>' onclick='return confirm("Bạn có chắc chắn muốn xóa lịch hẹn này?") && showDeleteTooltip(<?php echo $appointment["id"]; ?>)'>Xóa</a>
                                      <div class='tooltip-container'>
                                      <span class='tooltip' id='deleteTooltip<?php echo $appointment["id"]; ?>'>Xóa lịch hẹn thành công!</span>
                                      </div>
                                  </td>
                                </tr>
                                  <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                 <button id="show-all-past-appointments" style="display:<?php echo (count(array_filter($pastAppointments, function($appointment) use ($twoWeeksAgo) { return $appointment['date'] < $twoWeeksAgo; })) > 0) ? 'block':'none'; ?>">Hiện tất cả</button>
                <div id="all-past-appointments" style="display: none;">
                    <?php if (count(array_filter($pastAppointments, function($appointment) use ($twoWeeksAgo) { return $appointment['date'] < $twoWeeksAgo; })) > 0): ?>
                       <p>Lịch cũ hơn 2 tuần:</p>
                        <table class='schedule-table'>
                            <thead>
                                <tr>
                                    <th>Ngày</th>
                                    <th>Giờ bắt đầu</th>
                                    <th>Giờ kết thúc</th>
                                    <th>Ghi chú</th>
                                    <th>Địa điểm</th>
                                    <th>Người liên quan</th>
                                    <th>Loại công việc</th>
                                    <th>Người đặt</th>
                                     <th>Hành động</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                foreach ($pastAppointments as $appointment):
                                     if ($appointment['date'] < $twoWeeksAgo) :
                                    $date = new DateTime($appointment['date']);
                                    $dayOfWeek = $date->format('l');
                                    $formattedDate = $date->format('d/m/Y');
                                    $dayOfWeekVietnamese = '';
                                    switch ($dayOfWeek) {
                                        case 'Monday': $dayOfWeekVietnamese = 'Thứ 2'; break;
                                        case 'Tuesday': $dayOfWeekVietnamese = 'Thứ 3'; break;
                                        case 'Wednesday': $dayOfWeekVietnamese = 'Thứ 4'; break;
                                        case 'Thursday': $dayOfWeekVietnamese = 'Thứ 5'; break;
                                        case 'Friday': $dayOfWeekVietnamese = 'Thứ 6'; break;
                                        case 'Saturday': $dayOfWeekVietnamese = 'Thứ 7'; break;
                                        case 'Sunday': $dayOfWeekVietnamese = 'Chủ Nhật'; break;
                                    }
                                ?>
                                    <tr>
                                        <td><?php echo $dayOfWeekVietnamese . ' - ' . $formattedDate; ?></td>
                                        <td><?php echo $appointment['start_time']; ?></td>
                                        <td><?php echo $appointment['end_time']; ?></td>
                                        <td><?php echo convertUrlsToLinks($appointment['notes']); ?></td>
                                        <td><?php echo $appointment['location']; ?></td>
                                        <td><?php echo $appointment['related_people']; ?></td>
                                        <td><?php echo $appointment['type']; ?></td>
                                        <td><?php echo $appointment['name']; ?></td>
                                         <td style='position: relative;'>
                                      <button class='edit-button' onclick='showEditPopup(<?php echo $appointment["id"]; ?>)'>Sửa</button>
                                      <a href='?delete=<?php echo $appointment["id"]; ?>' onclick='return confirm("Bạn có chắc chắn muốn xóa lịch hẹn này?") && showDeleteTooltip(<?php echo $appointment["id"]; ?>)'>Xóa</a>
                                      <div class='tooltip-container'>
                                      <span class='tooltip' id='deleteTooltip<?php echo $appointment["id"]; ?>'>Xóa lịch hẹn thành công!</span>
                                      </div>
                                  </td>
                                    </tr>
                                      <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>Không có lịch hẹn nào cũ hơn 2 tuần.</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="tab-content" id="history">
                <h2>Lịch sử thao tác</h2>
                <?php
                $history = getHistory($conn);
                if (count($history) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Thời gian</th>
                                <th>Người thực hiện</th>
                                <th>Thao tác</th>
                                <th>Thông tin</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $item): ?>
                                <tr>
                                    <td><?php echo $item['timestamp']; ?></td>
                                    <td><?php echo $item['user']; ?></td>
                                    <td><?php echo $item['action']; ?></td>
                                    <td><?php echo $item['details']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>Không có lịch sử thao tác.</p>
                <?php endif; ?>
            </div>
            <div class="tab-content" id="notifications">
                <h2>Thông báo đã gửi</h2>
                <?php
                $notifications = getTelegramNotifications($conn);
                if (count($notifications) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Thời gian gửi</th>
                                <th>Nội dung</th>
                                <th>Nhóm nhận</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notifications as $notification): ?>
                                <tr>
                                    <td><?php echo $notification['sent_at']; ?></td>
                                    <td><?php echo $notification['content']; ?></td>
                                    <td><?php echo $notification['recipient']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>Không có thông báo nào được gửi.</p>
                <?php endif; ?>
            </div>
            <div class="tab-content" id="weekly-schedule">
                <h2>Lịch tuần này</h2>
                <?php
                $currentWeekStart = date('Y-m-d', strtotime('monday this week'));
                $currentWeekEnd = date('Y-m-d', strtotime('sunday this week'));
                $sql = "SELECT * FROM appointments WHERE date BETWEEN :start_date AND :end_date ORDER BY date ASC, start_time ASC";
                $stmt = $conn->prepare($sql);
                $stmt->bindParam(':start_date', $currentWeekStart);
                $stmt->bindParam(':end_date', $currentWeekEnd);
                $stmt->execute();
                $weeklyAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $weekDays = [];
                for ($i = 0; $i < 7; $i++) {
                    $date = date('Y-m-d', strtotime($currentWeekStart . " +$i days"));
                    $weekDays[$date] = [];
                }

                foreach ($weeklyAppointments as $appointment) {
                    $weekDays[$appointment['date']][] = $appointment;
                }

                foreach ($weekDays as $date => $dayAppointments):
                    $dateObj = new DateTime($date);
                    $dayOfWeek = $dateObj->format('l');
                    $formattedDate = $dateObj->format('d/m/Y');
                    switch ($dayOfWeek) {
                        case 'Monday': $dayOfWeekVN = 'Thứ 2'; break;
                        case 'Tuesday': $dayOfWeekVN = 'Thứ 3'; break;
                        case 'Wednesday': $dayOfWeekVN = 'Thứ 4'; break;
                        case 'Thursday': $dayOfWeekVN = 'Thứ 5'; break;
                        case 'Friday': $dayOfWeekVN = 'Thứ 6'; break;
                        case 'Saturday': $dayOfWeekVN = 'Thứ 7'; break;
                        case 'Sunday': $dayOfWeekVN = 'Chủ Nhật'; break;
                    }
                ?>
                    <div class="appointment-day">
                        <h3><?php echo $dayOfWeekVN . ' - ' . $formattedDate; ?></h3>
                        <?php if (empty($dayAppointments)): ?>
                            <p>Không có lịch hẹn</p>
                        <?php else: ?>
                            <ul>
                                <?php foreach ($dayAppointments as $apt): ?>
                                    <li>
                                        <span><?php echo $apt['start_time'] . ' - ' . $apt['end_time']; ?></span>
                                        <span>Người đặt: <?php echo $apt['name']; ?></span>
                                        <?php if (!empty($apt['notes'])): ?>
                                            <span>Ghi chú: <?php echo $apt['notes']; ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($apt['location'])): ?>
                                            <span>Địa điểm: <?php echo $apt['location']; ?></span>
                                         <?php endif; ?>
                                          <?php if (!empty($apt['related_people'])): ?>
                                            <span>Người liên quan: <?php echo $apt['related_people']; ?></span>
                                         <?php endif; ?>
                                         <?php if (!empty($apt['type'])): ?>
                                            <span>Loại công việc: <?php echo $apt['type']; ?></span>
                                         <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="tab-content" id="settings">
                <?php if (!$showSettings): ?>
                    <form method="post" id="password-form">
                        <label for="password">Nhập mật khẩu:</label>
                        <input type="password" id="password" name="password">
                        <button type="submit">Xác nhận</button>
                    </form>
                <?php else: ?>
                    <h2>Cài đặt</h2>
                    <form method="post">
                        <button type="submit" name="check_schedule">Kiểm tra lịch tuần tới</button>
                         <?php
                            if (isset($_POST['check_schedule'])) {
                                $appointments = getNextWeekAppointments($conn);
                                $message = formatTelegramMessage($appointments);
                                echo "<h3>Lịch tuần tới:</h3>";
                                if (empty($appointments)) {
                                    echo "<p>Không có lịch hẹn nào trong tuần tới.</p>";
                                } else {
                                    echo "<div style='margin-bottom: 20px;padding: 15px;background: #f8f9fa;border-radius: 5px;'>";
                                    echo nl2br($message);
                                    echo "</div>";
                                }
                            }
                            ?>
                    </form>
                    <form method="post">
                        <button type="submit" name="send_notification">Gửi thông báo lịch tuần tới</button>
                    </form>
                    <form method="post">
                        <button type="submit" name="send_reminder">Nhắc nhở đặt lịch</button>
                    </form>
                    <?php if (isset($success)): ?>
                        <?php if ($success): ?>
                            <div class="success-message">Đã gửi thông báo thành công!</div>
                        <?php else: ?>
                            <div class="error-message">
                                Có lỗi xảy ra khi gửi thông báo:<br>
                                <?php foreach ($errors as $error): ?>
                                    <?php echo $error; ?><br>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
      <!-- Popup form for editing appointments -->
<div id="editPopup" class="popup-overlay">
  <div class="popup-content">
    <h2>Chỉnh sửa lịch hẹn</h2>
    <form id="editForm" method="post">
      <input type="hidden" id="edit_id" name="update_id">
      <div class="form-group">
        <label for="edit_date">Ngày:</label>
        <input type="text" id="edit_date" name="update_date" required>
      </div>
      <div class="form-group">
        <label for="edit_start_time">Giờ bắt đầu:</label>
        <select id="edit_start_time" name="update_start_time">
          <?php
            for ($i = 0; $i < 24; $i++) {
              for ($j = 0; $j < 2; $j++) {
                $hour = str_pad($i, 2, '0', STR_PAD_LEFT);
                $minute = str_pad($j * 30, 2, '0', STR_PAD_LEFT);
                $time = $hour . ':' . $minute;
                echo "<option value=\"$time\">$time</option>";
              }
            }
          ?>
        </select>
      </div>
      <div class="form-group">
        <label for="edit_end_time">Giờ kết thúc:</label>
        <select id="edit_end_time" name="update_end_time">
          <?php
            for ($i = 0; $i < 24; $i++) {
              for ($j = 0; $j < 2; $j++) {
                $hour = str_pad($i, 2, '0', STR_PAD_LEFT);
                $minute = str_pad($j * 30, 2, '0', STR_PAD_LEFT);
                $time = $hour . ':' . $minute;
                echo "<option value=\"$time\">$time</option>";
              }
            }
          ?>
        </select>
      </div>
      <div class="form-group">
        <label for="edit_notes">Ghi chú:</label>
        <textarea id="edit_notes" name="update_notes"></textarea>
      </div>
      <div class="form-group">
        <label for="edit_location">Địa điểm:</label>
        <input type="text" id="edit_location" name="update_location">
      </div>
      <div class="form-group">
        <label for="edit_related_people">Người liên quan:</label>
        <input type="text" id="edit_related_people" name="update_related_people">
      </div>
      <div class="form-group">
        <label for="edit_type">Loại công việc:</label>
        <select id="edit_type" name="update_type">
            <option value="0. TẬP TRUNG CÔNG VIỆC - HẠN CHẾ LIÊN HỆ">0. TẬP TRUNG CÔNG VIỆC - HẠN CHẾ LIÊN HỆ</option>
            <option value="1. PG MEETING">1. PG MEETING</option>
            <option value="2. LIVESTREAM / QUAY CHỤP">2. LIVESTREAM / QUAY CHỤP</option>
            <option value="3. WORKSHOP / GIẢNG DẠY">3. WORKSHOP / GIẢNG DẠY</option>
            <option value="4. THỰC ĐỊA">4. THỰC ĐỊA</option>
            <option value="5. ĐỐI TÁC RIÊNG">5. ĐỐI TÁC RIÊNG</option>
            <option value="6. RIÊNG TƯ">6. RIÊNG TƯ</option>
        </select>
      </div>
      <button type="submit" name="update_appointment">Cập nhật</button>
      <button type="button" onclick="hideEditPopup()">Hủy</button>
    </form>
  </div>
</div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        flatpickr("#date", {
            dateFormat: "d/m/Y",
            locale: "vi",
        });
         flatpickr("#edit_date", {
            dateFormat: "d/m/Y",
            locale: "vi",
        });
    });
        const tabs = document.querySelectorAll('.tab');
        const tabContents = document.querySelectorAll('.tab-content');
        const addAppointmentBtn = document.getElementById('add-appointment-btn');
        const weeklyScheduleBtn = document.getElementById('weekly-schedule-btn');
        const historyBtn = document.getElementById('history-btn');
        const notificationsBtn = document.getElementById('notifications-btn');
        const settingsBtn = document.getElementById('settings-btn');

        
        function activateTab(tabId) {
            tabs.forEach(tab => tab.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));
            document.querySelector(`.tab[data-target="${tabId}"]`).classList.add('active');
            document.getElementById(tabId).classList.add('active');
        }
        
        addAppointmentBtn.addEventListener('click', function() {
            activateTab('add-appointment');
        });

        weeklyScheduleBtn.addEventListener('click', function() {
            activateTab('weekly-schedule');
        });

        historyBtn.addEventListener('click', function() {
            activateTab('history');
        });

        notificationsBtn.addEventListener('click', function() {
            activateTab('notifications');
        });

        settingsBtn.addEventListener('click', function() {
            activateTab('settings');
        });
        
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const target = tab.dataset.target;
                activateTab(target);
            });
        });
        
         function validateTime() {
             const startTime = document.getElementById('start_time').value;
             const endTime = document.getElementById('end_time').value;
              if(startTime >= endTime) {
                  alert('Giờ kết thúc phải sau giờ bắt đầu!');
                   return false;
              }
              return true;
         }
         
          function showDeleteTooltip(id) {
            const tooltip = document.getElementById('deleteTooltip' + id);
            if (tooltip) {
              tooltip.style.display = 'block';
              setTimeout(() => {
                tooltip.style.display = 'none';
              }, 2000); // Ẩn sau 2 giây
            }
          }
          
           function showEditPopup(id) {
              // Get the appointment data from the table row
              var row = document.querySelector('.schedule-table tr:has([href*="delete=' + id + '"])');
              var date = row.cells[0].textContent.split(' - ')[1]; // Extract date from the first cell
              var startTime = row.cells[1].textContent;
              var endTime = row.cells[2].textContent;
              var notes = row.cells[3].textContent;
              var location = row.cells[4].textContent;
              var relatedPeople = row.cells[5].textContent;
              var type = row.cells[6].textContent;
              var name = row.cells[7].textContent;
            
              // Convert date from dd/mm/yyyy to yyyy-mm-dd
              var parts = date.split('/');
              var formattedDate = parts[2] + '-' + parts[1] + '-' + parts[0];
            
              // Fill the popup form with the appointment data
              document.getElementById('edit_id').value = id;
              document.getElementById('edit_date').value = formattedDate;
              document.getElementById('edit_start_time').value = startTime;
              document.getElementById('edit_end_time').value = endTime;
              document.getElementById('edit_notes').value = notes;
              document.getElementById('edit_location').value = location;
              document.getElementById('edit_related_people').value = relatedPeople;
              document.getElementById('edit_type').value = type;
              // Show the popup
              document.getElementById('editPopup').style.display = 'flex';
            }
            
            function hideEditPopup() {
              // Hide the popup
              document.getElementById('editPopup').style.display = 'none';
            }
        document.getElementById('show-all-past-appointments').addEventListener('click', function() {
          var oldScheduleDiv = document.getElementById('all-past-appointments');
          oldScheduleDiv.style.display = 'block';
           this.style.display = 'none';
        });
    </script>
</body>
</html>