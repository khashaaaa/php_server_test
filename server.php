<?php

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Coroutine;
use Workerman\Events\Swoole;

require_once __DIR__ . '/vendor/autoload.php';

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $this->pdo = new \PDO(
            "pgsql:host=localhost;dbname=php_server_test",
            "postgres",
            "123",
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}

class ApiResponse {
    public static function json($data, $status = 200) {
        return json_encode([
            'status' => $status,
            'data' => $data ?: [],
            'timestamp' => time()
        ]);
    }

    public static function error($message, $status = 400) {
        return json_encode([
            'status' => $status,
            'error' => $message,
            'timestamp' => time()
        ]);
    }
}

$worker = new Worker('http://0.0.0.0:8084');
$worker->count = 6;
$worker->name = 'ApiServer';
$worker->eventLoop = Swoole::class;

$router = function($path, $method) {
    $routes = [
        'GET' => [
            '/' => 'getUsers',
            '/(\d+)' => 'getUser'
        ],
        'POST' => [
            '/' => 'createUser'
        ],
        'PUT' => [
            '/(\d+)' => 'updateUser'
        ],
        'DELETE' => [
            '/(\d+)' => 'deleteUser'
        ]
    ];

    foreach ($routes[$method] ?? [] as $pattern => $handler) {
        if (preg_match("#^$pattern$#", $path, $matches)) {
            array_shift($matches);
            return [$handler, $matches];
        }
    }
    return ['notFound', []];
};

$handlers = [
    'getUsers' => function() {
        $db = Database::getInstance();
        $result = $db->query("SELECT id, name, email FROM \"users\"");
        return ApiResponse::json($result->fetchAll());
    },

    'getUser' => function($id) {
        $db = Database::getInstance();
        $result = $db->query("SELECT name, email FROM \"users\" WHERE id = ?", [$id]);
        $item = $result->fetch();
        return $item ? ApiResponse::json([$item]) : ApiResponse::json([]);
    },

    'createUser' => function($data) {
        if (empty($data['name'])) {
            return ApiResponse::error('Name is required', 400);
        }

        $db = Database::getInstance();
        $stmt = $db->query(
            "INSERT INTO \"users\" (name, email) VALUES (?, ?) RETURNING id, name, email",
            [$data['name'], $data['email'] ?? null]
        );
        return ApiResponse::json([$stmt->fetch()], 201);
    },

    'updateUser' => function($id, $data) {
        if (empty($data['name'])) {
            return ApiResponse::error('Name is required', 400);
        }

        $db = Database::getInstance();
        $stmt = $db->query(
            "UPDATE \"users\" SET name = ?, email = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? RETURNING id, name, email",
            [$data['name'], $data['email'] ?? null, $id]
        );
        $item = $stmt->fetch();
        return $item ? ApiResponse::json([$item]) : ApiResponse::json([]);
    },

    'deleteUser' => function($id) {
        $db = Database::getInstance();
        $stmt = $db->query("DELETE FROM \"users\" WHERE id = ? RETURNING id, name, email", [$id]);
        $result = $stmt->fetch();
        return $result ? ApiResponse::json(['deleted' => true]) : ApiResponse::json([]);
    },

    'notFound' => function() {
        return ApiResponse::json([]);
    }
];

$worker->onMessage = function(TcpConnection $connection, Request $request) use ($router, $handlers) {
    $path = $request->path();
    $method = $request->method();
    
    [$handler, $params] = $router($path, $method);

    Coroutine::create(function() use ($connection, $request, $handler, $params, $handlers) {
        try {
            $data = [];
            if (in_array($request->method(), ['POST', 'PUT'])) {
                $data = json_decode($request->rawBody(), true) ?? [];
            }

            $result = $handlers[$handler](...array_merge($params, [$data]));
            $connection->send($result);
        } catch (\Exception $e) {
            $connection->send(ApiResponse::error($e->getMessage(), 500));
        }
    });
};

Worker::runAll();