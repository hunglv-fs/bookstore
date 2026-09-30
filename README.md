# Bookstore MVP

REST API bán sách online — Laravel 12, PHP 8.4, PostgreSQL 17, Sanctum.

> Trạng thái: **MVP hoàn thành** (Phase 1–7). Đặc tả: [bookstore-mvp-claude-code.md](bookstore-mvp-claude-code.md).

| Tài liệu | Nội dung |
|---|---|
| [docs/api.md](docs/api.md) | Mọi endpoint: request, response, lỗi |
| [docs/database.md](docs/database.md) | Bảng, quan hệ, constraint, index và query mà index phục vụ |
| [docs/architecture.md](docs/architecture.md) | Các tầng, flow checkout, transaction, row lock, chống race condition |

## Yêu cầu

- Docker + Docker Compose

PHP/Composer trên máy host là tùy chọn — mọi lệnh đều chạy được trong container `app`.

## Cài đặt

```bash
docker compose up -d                              # app (PHP 8.4) + postgres (17)
docker compose exec app composer install
docker compose exec app php artisan key:generate  # chỉ cần nếu .env chưa có APP_KEY
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan test
```

API chạy tại `http://localhost:8000/api` (health check: `http://localhost:8000/up`).

Trên clone mới, lần khởi động đầu tiên container tự chạy `composer install` và tạo `.env` (kèm `APP_KEY`) từ `.env.example`, nên mất khoảng 1–2 phút trước khi API phản hồi.

| Biến môi trường | Mặc định | Ý nghĩa |
|---|---|---|
| `APP_PORT` | 8000 | Port API trên máy host |
| `DB_FORWARD_PORT` | 5433 | Port PostgreSQL trên máy host (tránh đụng PostgreSQL cài sẵn ở 5432) |

### Chạy từ máy host (tùy chọn)

Nếu máy có PHP 8.4+ với `pdo_pgsql`, có thể chạy thẳng `composer install`, `php artisan migrate --seed`, `php artisan test` trên host.
`.env` trỏ tới `127.0.0.1:5433`; bên trong container, `docker-compose.yml` ghi đè thành `postgres:5432`.

## Database

| Database         | Dùng cho |
|------------------|---|
| `bookstore`      | Development (`migrate --seed`) |
| `bookstore_test` | Test suite (`phpunit.xml`), tạo tự động khi khởi tạo volume |

Nếu volume `pgdata` được tạo trước khi có script init, tạo DB test thủ công:

```bash
docker compose exec postgres psql -U bookstore -d bookstore -c "CREATE DATABASE bookstore_test"
```

## Test

```bash
docker compose exec app php artisan test                       # toàn bộ (130+ test)
docker compose exec app php artisan test --filter=Concurrency  # chỉ test đồng thời
```

- Chạy trên **PostgreSQL thật**, không dùng SQLite — CHECK constraint, partial index và row-level locking phải được kiểm chứng trên đúng engine.
- `failOnDeprecation` bật trong `phpunit.xml`: cảnh báo deprecation của PHP làm test fail.
- `tests/Feature/Concurrency` chạy mỗi request trong **một process PHP riêng** (kết nối DB riêng); helper chờ tới khi mọi worker đang đợi cùng một lock rồi mới thả, nên các worker tranh chấp thật sự cùng lúc. Dữ liệu được commit thật và dọn bằng truncate.

| Nhóm | Thư mục | Nội dung chính |
|---|---|---|
| Auth | `tests/Feature/Auth` | register, login, logout, sai mật khẩu, tài khoản bị khóa |
| Catalog | `tests/Feature/Catalog` | filter, sort, phân trang, không N+1, cây category |
| Cart | `tests/Feature/Cart` | thêm, sửa, xóa, gộp sách trùng, quyền truy cập |
| Inventory | `tests/Feature/Inventory` | reserve, release, sale, idempotency, đối soát sổ cái |
| Order | `tests/Feature/Order` | checkout (thành công / cart rỗng / sách inactive / thiếu hàng), xem đơn, hủy đơn, admin đổi trạng thái, địa chỉ |
| Payment | `tests/Feature/Payment` | COD được tạo, số tiền khớp đơn, paid khi giao, failed khi hủy |
| Database | `tests/Feature/Database` | database tự từ chối dữ liệu sai (CHECK, UNIQUE, FK) |
| Concurrency | `tests/Feature/Concurrency` | không oversell, không deadlock, không xử lý kho/payment hai lần |

## Tài khoản seed

Mật khẩu tất cả: `password`

| Email                     | Role     |
|---------------------------|----------|
| `admin@bookstore.test`    | admin    |
| `customer@bookstore.test` | customer (có sẵn 3 đơn: pending, delivered, cancelled) |
| + 9 customer ngẫu nhiên   | customer |

Seed đầy đủ: 2 roles, 11 users, 10 customers (mỗi người 1 địa chỉ mặc định), 5 publishers, 10 authors,
10 categories (3 gốc, 7 con), 100 books (5 inactive) kèm tác giả, category và 1–3 ảnh,
100 inventory records (10 sách hết hàng; mỗi lần nhập có bút toán `purchase`), 3 đơn hàng kèm payment COD.

## API

| Method | Endpoint | Auth | Mô tả |
|---|---|---|---|
| POST | `/api/register` | — | Đăng ký customer, trả về token |
| POST | `/api/login` | — | Đăng nhập, trả về token |
| POST | `/api/logout` | Bearer | Thu hồi token hiện tại (204) |
| GET | `/api/me` | Bearer | Thông tin user + role + customer |
| GET | `/api/books` | — | Sách active: `keyword`, `category_id`, `author_id`, `publisher_id`, `min_price`, `max_price`, `sort`, `per_page` |
| GET | `/api/books/{id}` | — | Chi tiết sách, kèm tồn kho khả dụng |
| GET | `/api/categories` | — | Cây category |
| GET | `/api/categories/{id}` | — | Category kèm parent và children |
| GET | `/api/cart` | Customer | Cart active |
| POST | `/api/cart/items` | Customer | Thêm sách (cộng dồn nếu đã có) |
| PATCH | `/api/cart/items/{id}` | Customer | Đổi số lượng |
| DELETE | `/api/cart/items/{id}` | Customer | Xóa dòng khỏi cart |
| GET | `/api/addresses` | Customer | Địa chỉ giao hàng |
| POST | `/api/addresses` | Customer | Thêm địa chỉ |
| POST | `/api/orders` | Customer | Checkout cart → đơn hàng (COD) |
| GET | `/api/orders` | Customer | Đơn hàng của tôi |
| GET | `/api/orders/{id}` | Customer | Chi tiết đơn |
| POST | `/api/orders/{id}/cancel` | Customer | Hủy đơn (trước khi giao vận chuyển) |
| GET | `/api/orders/{id}/payments` | Customer | Payment của đơn |
| PATCH | `/api/admin/orders/{id}/status` | Admin | Chuyển trạng thái đơn (delivered → trừ kho + thu COD) |

### Thử nhanh một lượt mua hàng

```bash
API=http://localhost:8000/api
TOKEN=$(curl -s -X POST $API/login -H 'Content-Type: application/json' \
  -d '{"email":"customer@bookstore.test","password":"password"}' | php -r 'echo json_decode(stream_get_contents(STDIN))->data->token;')

curl -s "$API/books?keyword=postgresql&sort=price_asc&per_page=3"
curl -s -X POST $API/cart/items -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"book_id": 1, "quantity": 2}'
curl -s $API/addresses -H "Authorization: Bearer $TOKEN"                      # lấy address_id
curl -s -X POST $API/orders -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"address_id": 1}'
```

## Nhập kho

Chưa có API admin cho inventory (hoãn sau MVP). Nhập kho qua artisan (ghi bút toán `purchase`):

```bash
docker compose exec app php artisan inventory:receive <book_id> <quantity>
```

## Cấu trúc code

```text
app/
├── Console/Commands/      inventory:receive
├── Enums/                 RoleName, BookStatus, CartStatus, OrderStatus (state machine), PaymentMethod, ...
├── Exceptions/            InsufficientStockException (422), InventoryException
├── Http/
│   ├── Controllers/Api/   mỏng: gọi service, trả Resource
│   ├── Middleware/        active (tài khoản bị khóa), role:customer|admin
│   ├── Requests/          Form Request cho mọi input
│   └── Resources/         định dạng JSON
├── Models/
├── Policies/              CartItemPolicy, OrderPolicy (404 cho bản ghi của người khác)
└── Services/              Auth, Cart, Inventory, Checkout, Order, Payment, Address
database/migrations/       schema + CHECK / partial unique index
tests/Support/concurrency-worker.php   worker cho test đồng thời
```

## Production

- Bắt buộc `APP_DEBUG=false`: lỗi 500 chỉ trả `{"message": "Server Error"}` — không lộ stack trace hay thông tin database (có test kiểm chứng). Lỗi 4xx luôn chỉ gồm `message` (và `errors` với 422) kể cả khi debug.
- Container `app` dùng PHP built-in server (4 worker) — đủ cho dev/demo. Production nên chạy PHP-FPM + Nginx (hoặc Laravel Octane).
- Token Sanctum mặc định không hết hạn. Khi triển khai thật nên đặt `SANCTUM_EXPIRATION` (số phút) trong `.env`.
