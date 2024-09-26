<?php

namespace App\Controller;

use App\Entity\Order;
use Predis\Client;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class OrderController extends AbstractController
{
    private $redis;
    private string $authKey;

    public function __construct(Client $redis)
    {
        $this->redis = $redis; // Инициализируем клиент Redis
        $this->authKey = $_ENV['AUTH_KEY'];
    }
    #[Route('/orders', name: 'create_order')]
    public function createOrder(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Проверка на наличие элементов в массиве items
        if (empty($data['items'])) {
            return new JsonResponse(['error' => 'Items cannot be empty'], 400);
        }

        // Создание нового заказа
        $order = new Order();
        $order->setItems($data['items']);
        $orderId = uniqid(); // Генерация уникального ID для заказа
        $order->setOrderId($orderId); // Установка ID заказа

        // Сохранение заказа в Redis
        $this->redis->set("order:$orderId", json_encode([
            'order_id' => $orderId,
            'items' => $order->getItems(),
            'done' => $order->isDone(),
        ]));

        // Формирование ответа
        return new JsonResponse([
            'order_id' => $orderId,
            'items' => $order->getItems(),
            'done' => $order->isDone(),
        ], 201);
    }

    #[Route('/orders/{order_id}/items', name: 'add_items_to_order')]
    public function addItemsToOrder(Request $request, $order_id): JsonResponse
    {
        // Получаем данные из запроса
        $itemIds = json_decode($request->getContent(), true);

        // Проверяем статус заказа
        $status = $this->redis->hget("order:$order_id", 'status');
        if ($status === 'done') {
            return new JsonResponse([
                'error' => 'Нельзя добавлять товары в завершенный заказ',
            ], 400);
        }

        // Добавляем товары в заказ
        foreach ($itemIds as $itemId) {
            $this->redis->sadd("order:$order_id:item_ids", $itemId);
        }

        return new JsonResponse([
            'result' => 'Товары успешно добавлены в заказ',
        ], 200);
    }

    #[Route('/orders/{order_id}', name: 'get_order')]
    public function getOrder($order_id): JsonResponse
    {
        // Попробуем получить заказ из Redis
        $orderData = $this->redis->get("order:$order_id");

        if ($orderData) {
            return new JsonResponse(json_decode($orderData, true), 200);
        } else {
            return new JsonResponse(['error' => 'Order not found'], 404);
        }
    }

    #[Route('/orders/{order_id}', name: 'mark_order_as_done')]
    public function markOrderAsDone(Request $request, $order_id): JsonResponse
    {
        // Проверяем наличие заголовка X-Auth-Key
        $authKey = $request->headers->get('X-Auth-Key');
        if ($authKey !== $this->authKey) {
            return new JsonResponse([
                'error' => 'Недостаточно прав для выполнения операции',
            ], 403);
        }

        // Проверяем статус заказа
        $status = $this->redis->hget("order:$order_id", 'status');
        if ($status === 'done') {
            return new JsonResponse([
                'error' => 'Заказ уже выполнен',
            ], 400);
        }

        // Помечаем заказ как выполненный
        $this->redis->hset("order:$order_id", 'status', 'done');

        return new JsonResponse([
            'message' => 'Заказ успешно отмечен как выполненный',
        ], 200);
    }

    #[Route('/orders', name: 'get_orders', methods: ['GET'])]
    public function getOrders(Request $request): JsonResponse
    {
        // Проверяем наличие заголовка X-Auth-Key
        $authKey = $request->headers->get('X-Auth-Key');
        if ($authKey !== $this->authKey) {
            return new JsonResponse([
                'error' => 'Недостаточно прав для выполнения операции',
            ], 403);
        }

        // Получаем параметр done из запроса
        $doneParam = $request->query->get('done');

        // Получаем все заказы
        $orders = $this->redis->keys('order:*');
        $result = [];

        foreach ($orders as $orderKey) {
            $orderData = $this->redis->hgetall($orderKey);
            $orderId = basename($orderKey);
            $isDone = $orderData['status'] === 'done';

            // Фильтруем по параметру done, если он передан
            if ($doneParam !== null) {
                if (($doneParam == 1 && !$isDone) || ($doneParam == 0 && $isDone)) {
                    continue; // Пропускаем заказы, которые не соответствуют фильтру
                }
            }

            $result[] = [
                'order_id' => $orderId,
                'done' => $isDone,
            ];
        }

        return new JsonResponse(['message' => $result,], 200);
    }
}
