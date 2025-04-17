<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();

// Проверка на выход
if (!empty($_GET['logout'])) {
    session_destroy();
    header('Location: ?');
}

// Выводим сообщение об успешном сохранении
if (!empty($_COOKIE['save'])) {
    setcookie('save', '', 100000);
    $messages[] = 'Спасибо, результаты сохранены.';
}

// Подключаемся к базе
try {
    $db = new PDO('mysql:host=localhost;dbname=u68818', 'u68818', '9972335', [
        PDO::ATTR_PERSISTENT => true,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    print('Error : ' . $e->getMessage());
    exit();
}

$errors = [];
$values = [
    'fio' => '',
    'email' => '',
    'phone' => '',
    'dob' => '',
    'gender' => '',
    'bio' => '',
    'contract' => '',
    'languages' => []
];

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    // Если пользователь авторизован, получаем данные из базы
    if (!empty($_SESSION['login'])) {
        $stmt = $db->prepare("SELECT * FROM applications WHERE id = (SELECT application_id FROM users WHERE login = ?)");
        $stmt->execute([$_SESSION['login']]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($data) {
            $values['fio'] = strip_tags($data['full_name']);
            $values['phone'] = strip_tags($data['phone']);
            $values['email'] = strip_tags($data['email']);
            $values['dob'] = strip_tags($data['birth_date']);
            $values['gender'] = strip_tags($data['gender']);
            $values['bio'] = strip_tags($data['bio']);
            $values['contract'] = $data['contract'];

            $stmt = $db->prepare("SELECT l.name FROM languages l 
                                  JOIN application_languages al ON l.id = al.language_id 
                                  WHERE al.application_id = ?");
            $stmt->execute([$data['id']]);
            $values['languages'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }

    include('form.php');
    exit();
} else {
    // POST-запрос
    $errors = [];
    if (empty($_POST['fio'])) {
        $errors['fio'] = 'Введите ФИО.';
    } else {
        $values['fio'] = $_POST['fio'];
    }

    if (empty($_POST['email'])) {
        $errors['email'] = 'Введите Email.';
    } else {
        $values['email'] = $_POST['email'];
    }

    if (empty($_POST['phone'])) {
        $errors['phone'] = 'Введите телефон.';
    } else {
        $values['phone'] = $_POST['phone'];
    }

    if (empty($_POST['dob'])) {
        $errors['dob'] = 'Введите дату рождения.';
    } else {
        $values['dob'] = $_POST['dob'];
    }

    if (empty($_POST['gender'])) {
        $errors['gender'] = 'Выберите пол.';
    } else {
        $values['gender'] = $_POST['gender'];
    }

    if (empty($_POST['bio'])) {
        $errors['bio'] = 'Напишите что-нибудь о себе.';
    } else {
        $values['bio'] = $_POST['bio'];
    }

    if (empty($_POST['languages']) || !is_array($_POST['languages'])) {
        $errors['languages'] = 'Выберите хотя бы один язык программирования.';
    } else {
        $values['languages'] = $_POST['languages'];
    }

    $values['contract'] = isset($_POST['contract']) ? 1 : 0;

    if (!empty($errors)) {
        include('form.php');
        exit();
    }

    if (!empty($_SESSION['login'])) {
        // Обновление данных авторизованного пользователя
        $stmt = $db->prepare("UPDATE applications 
                              SET full_name = ?, phone = ?, email = ?, birth_date = ?, gender = ?, bio = ?, contract = ? 
                              WHERE id = (SELECT application_id FROM users WHERE login = ?)");
        $stmt->execute([
            $_POST['fio'], $_POST['phone'], $_POST['email'],
            $_POST['dob'], $_POST['gender'], $_POST['bio'],
            isset($_POST['contract']) ? 1 : 0, $_SESSION['login']
        ]);

        $stmt = $db->prepare("SELECT application_id FROM users WHERE login = ?");
        $stmt->execute([$_SESSION['login']]);
        $app_id = $stmt->fetchColumn();

        $stmt = $db->prepare("DELETE FROM application_languages WHERE application_id = ?");
        $stmt->execute([$app_id]);
    } else {
        // Новый пользователь
        $stmt = $db->prepare("INSERT INTO applications 
                              (full_name, phone, email, birth_date, gender, bio, contract) 
                              VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['fio'], $_POST['phone'], $_POST['email'],
            $_POST['dob'], $_POST['gender'], $_POST['bio'],
            isset($_POST['contract']) ? 1 : 0
        ]);

        $app_id = $db->lastInsertId();

        // Генерация логина и пароля
        $login = 'user' . rand(1000, 9999);
        $pass = bin2hex(random_bytes(4));
        $hash = password_hash($pass, PASSWORD_DEFAULT);

        $stmt = $db->prepare("INSERT INTO users (login, pass, application_id) VALUES (?, ?, ?)");
        $stmt->execute([$login, $hash, $app_id]);

        setcookie('login', $login);
        setcookie('pass', $pass);
    }

    // Обработка языков программирования
    $stmt = $db->prepare("SELECT id FROM languages WHERE name = ?");
    $insertLang = $db->prepare("INSERT INTO languages (name) VALUES (?)");
    $linkStmt = $db->prepare("INSERT INTO application_languages (application_id, language_id) VALUES (?, ?)");

    foreach ($_POST['languages'] as $language) {
        $stmt->execute([$language]);
        $lang_id = $stmt->fetchColumn();

        if (!$lang_id) {
            $insertLang->execute([$language]);
            $lang_id = $db->lastInsertId();
        }

        $linkStmt->execute([$app_id, $lang_id]);
    }

    setcookie('save', '1');
    header('Location: ?');
}
?>
