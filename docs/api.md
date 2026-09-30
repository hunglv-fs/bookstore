# API

Base URL: `http://localhost:8000/api`. Mọi response là JSON.
Tài liệu này bao gồm toàn bộ endpoint của MVP.
Swagger UI (tự sinh từ code, gọi thử được): `http://localhost:8000/docs/api` — bấm **Authorize** và dán token lấy từ `/login`.

## Quy ước

- Endpoint cần đăng nhập: header `Authorization: Bearer <token>` (Sanctum personal access token).
- Response thành công bọc trong `data`. Danh sách phân trang có thêm `links` và `meta`.
- Tiền tệ trả về dạng string 2 chữ số thập phân: `"price": "250000.00"`.

### Lỗi

| Status | Khi nào | Body |
|---|---|---|
| 401 | Thiếu/sai token | `{"message": "Unauthenticated."}` |
| 403 | Tài khoản bị khóa; hoặc sai role (admin gọi `/cart`, `/orders`; customer gọi `/admin/...`) | `{"message": "Your account has been deactivated."}` / `{"message": "This action is only available to customers."}` |
| 404 | Không tìm thấy; sách `inactive`; hoặc bản ghi thuộc customer khác | `{"message": "Resource not found."}` |
| 422 | Dữ liệu không hợp lệ | `{"message": "...", "errors": {"field": ["..."]}}` |
| 429 | Vượt giới hạn request (register/login: 10/phút) | `{"message": "Too Many Attempts."}` |
| 500 | Lỗi server | `{"message": "Server Error"}` (khi `APP_DEBUG=false`) |

Lỗi 4xx luôn chỉ có `message` (và `errors` với 422), kể cả khi `APP_DEBUG=true`.

---

## Authentication

### POST /register
Đăng ký tài khoản customer. Luôn được gán role `customer`; mọi field `role`/`role_id`/`is_active` gửi lên đều bị bỏ qua.

| Field | Rule |
|---|---|
| name | required, string, ≤ 255 |
| email | required, email, unique (không phân biệt hoa thường) |
| password | required, ≥ 8 ký tự, cần `password_confirmation` |
| phone | nullable, 8–15 chữ số, có thể có `+` ở đầu |
| device_name | nullable, tên token (mặc định `api`) |

`201 Created`
```json
{
  "data": {
    "token": "1|ePXz...",
    "token_type": "Bearer",
    "user": {
      "id": 12, "name": "Tran Thi B", "email": "b@demo.vn", "phone": "0912345678",
      "avatar": null, "role": "customer", "is_active": true,
      "customer": {"id": 11, "name": "Tran Thi B", "email": "b@demo.vn", "phone": "0912345678"},
      "created_at": "2026-09-30T12:22:13+00:00"
    }
  }
}
```

### POST /login
Body: `email`, `password`, `device_name` (tùy chọn). Response `200`, cùng cấu trúc với register.
Sai email, sai mật khẩu hoặc tài khoản bị khóa đều trả `422` với cùng một thông báo trong `errors.email`.

### POST /logout  🔒
Thu hồi token đang dùng (các thiết bị khác vẫn đăng nhập). `204 No Content`.

### GET /me  🔒
User hiện tại kèm `role` và `customer` (`null` với admin).

---

## Books

### GET /books
Danh sách sách **active**, phân trang.

| Query | Mô tả |
|---|---|
| `keyword` | Tìm trong `title` (không phân biệt hoa thường, dạng chứa chuỗi) hoặc trùng khớp `isbn`. `%` và `_` được hiểu theo nghĩa đen |
| `category_id` | Lọc theo category, **bao gồm mọi category con cháu** (lọc "Programming" trả cả sách "Backend") |
| `author_id` | Sách có tác giả này |
| `publisher_id` | Sách của nhà xuất bản này |
| `min_price`, `max_price` | Khoảng giá, tính cả hai đầu. `max_price ≥ min_price` |
| `sort` | `newest` (mặc định), `oldest`, `price_asc`, `price_desc`, `title_asc` |
| `per_page` | 1–100, mặc định 15 |
| `page` | ≥ 1 |

`available_quantity` = `quantity - reserved_quantity` của kho (0 nếu sách chưa có dòng inventory); `in_stock` = `available_quantity > 0`.

Có thể kết hợp nhiều filter: `GET /books?keyword=laravel&category_id=2&sort=price_asc`.
Toàn bộ filter và sort chạy trong PostgreSQL. Mọi kiểu sort đều kèm `id` làm tiêu chí phụ để thứ tự giữa các trang ổn định.

`200 OK`
```json
{
  "data": [
    {
      "id": 16,
      "isbn": "9781234567897",
      "title": "TypeScript Essentials",
      "description": "...",
      "price": "150000.00",
      "published_at": "2026-08-29",
      "status": "active",
      "available_quantity": 29,
      "in_stock": true,
      "publisher": {"id": 3, "name": "Lotus Books", "website": "https://lotusbooks.example"},
      "authors": [{"id": 4, "name": "Cathy Walter"}],
      "categories": [{"id": 3, "name": "Frontend", "description": "...", "parent_id": 1}],
      "thumbnail": {"id": 30, "image_url": "https://...", "is_thumbnail": true, "sort_order": 0}
    }
  ],
  "links": {"first": "...?page=1", "last": "...?page=32", "prev": null, "next": "...?page=2"},
  "meta": {"current_page": 1, "last_page": 32, "per_page": 3, "total": 95, "from": 1, "to": 3, "path": "..."}
}
```

#### Vì sao dùng `paginate()` thay vì `cursorPaginate()`

- Catalog cần `total` và số trang để client hiển thị "Trang 3/32" và nhảy tới trang bất kỳ. `cursorPaginate()` chỉ hỗ trợ trang trước/sau.
- Người dùng sort theo nhiều cột (giá, tên, ngày xuất bản). Cursor pagination cần cursor dựa trên đúng các cột sort, và không hỗ trợ tốt `NULLS LAST` (như `published_at`).
- Ở quy mô catalog MVP, chi phí `COUNT(*)` và `OFFSET` không đáng kể.
- Khi nào nên đổi sang cursor: danh sách rất dài, chỉ cuộn tới/lui (ví dụ lịch sử đơn hàng, feed), hoặc khi `OFFSET` ở trang sâu trở nên chậm.

### GET /books/{id}
Chi tiết sách kèm `publisher`, `authors`, `categories`, `thumbnail` và toàn bộ `images` (theo `sort_order`).
Sách `inactive`, id không tồn tại hoặc id không phải số → `404`.

---

## Categories

### GET /categories
Toàn bộ cây category (sắp theo tên), các category con nằm trong `children`. Không phân trang: đây là danh sách nhỏ do admin quản lý, lấy bằng một query duy nhất.

```json
{
  "data": [
    {
      "id": 1, "name": "Programming", "description": "...", "parent_id": null,
      "children": [
        {"id": 2, "name": "Backend", "description": "...", "parent_id": 1, "children": []}
      ]
    }
  ]
}
```

### GET /categories/{id}
Một category kèm `parent` và các `children` trực tiếp.

---

## Cart  🔒 (chỉ customer)

Mỗi customer có đúng một cart `active`, tự tạo khi dùng lần đầu. Sau checkout, cart chuyển sang `converted` và lần thêm sách tiếp theo sẽ tạo cart mới.
Admin gọi các endpoint này → `403`. Item của customer khác, hoặc item thuộc cart đã `converted` → `404`.

Mọi endpoint cart trả về **toàn bộ cart** sau khi thay đổi, luôn với status `200`:

```json
{
  "data": {
    "id": 3,
    "status": "active",
    "items": [
      {
        "id": 7,
        "quantity": 2,
        "unit_price": "189000.00",
        "line_total": "378000.00",
        "is_available": true,
        "book": {"id": 98, "isbn": "9781234567897", "title": "The Little Treehouse", "thumbnail": {"id": 1, "image_url": "https://...", "is_thumbnail": true, "sort_order": 0}}
      }
    ],
    "total_quantity": 2,
    "subtotal": "378000.00"
  }
}
```

- `unit_price` / `subtotal` tính theo **giá hiện tại** của sách, chỉ để hiển thị. Giá thực sự tính tiền được chốt lúc checkout.
- `is_available = false` khi sách bị chuyển `inactive` sau khi đã thêm vào cart; checkout sẽ từ chối item này.
- Cart không kiểm tra hay giữ tồn kho — tồn kho được kiểm tra và reserve lúc checkout.

### GET /cart
Cart active của customer (tạo cart rỗng nếu chưa có).

### POST /cart/items
| Field | Rule |
|---|---|
| book_id | required, integer, sách tồn tại và `active` |
| quantity | required, integer, 1–100 |

Nếu sách đã có trong cart, số lượng được **cộng dồn** vào dòng cũ (không tạo dòng mới). Tổng sau khi cộng vượt 100 → `422`.

### PATCH /cart/items/{id}
Body: `quantity` (integer, 1–100) — đặt lại số lượng. Muốn xóa dòng thì dùng `DELETE`.

### DELETE /cart/items/{id}
Xóa dòng khỏi cart, trả về cart còn lại.

---

## Addresses  🔒 (chỉ customer)

Địa chỉ **chỉ tạo, không sửa/xóa** — một địa chỉ đã dùng cho đơn hàng không bao giờ thay đổi.

### GET /addresses
Danh sách địa chỉ của customer (mặc định lên đầu). Không phân trang.

### POST /addresses
| Field | Rule |
|---|---|
| receiver_name | required, ≤ 255 |
| phone | required, 8–15 chữ số, có thể có `+` |
| address | required, ≤ 255 |
| ward, district, city | required, ≤ 100 |
| is_default | boolean, tùy chọn |

Địa chỉ đầu tiên tự động là mặc định; tạo địa chỉ mới với `is_default: true` sẽ bỏ mặc định của địa chỉ cũ. `201 Created`.

---

## Orders  🔒 (chỉ customer)

Đơn của customer khác → `404`.

### POST /orders — Checkout
Tạo đơn từ **cart active** của customer.

| Field | Rule |
|---|---|
| address_id | required, phải là địa chỉ của chính customer |
| note | nullable, ≤ 1000 |
| payment_method | nullable, chỉ nhận `cod` (mặc định) |

`201 Created`
```json
{
  "data": {
    "id": 4,
    "order_no": "BK260930-J4VDJ4B5",
    "status": "pending",
    "payment_status": "unpaid",
    "can_cancel": true,
    "subtotal": "192000.00",
    "shipping_fee": "0.00",
    "total_amount": "192000.00",
    "note": "Gọi trước khi giao",
    "ordered_at": "2026-09-30T14:10:05+00:00",
    "address": {"id": 11, "receiver_name": "Le Van C", "phone": "0987654321", "address": "45 Le Loi", "ward": "Ben Thanh", "district": "District 1", "city": "Ho Chi Minh City", "is_default": true},
    "items": [
      {"id": 7, "quantity": 2, "unit_price": "96000.00", "subtotal": "192000.00", "book": {"id": 73, "isbn": "9781234567897", "title": "PostgreSQL Mastery"}}
    ],
    "payments": [
      {"id": 4, "method": "cod", "amount": "192000.00", "status": "pending", "transaction_no": null, "paid_at": null, "created_at": "2026-09-30T14:10:05+00:00"}
    ],
    "status_histories": [
      {"old_status": null, "new_status": "pending", "changed_by": 13, "note": "Order placed", "created_at": "2026-09-30T14:10:05+00:00"}
    ]
  }
}
```

- `unit_price` là giá sách **tại lúc checkout** và không đổi về sau.
- Sau khi thành công: tồn kho được reserve, payment COD `pending` được tạo với `amount = total_amount`, cart chuyển `converted` và rỗng.

Lỗi (`422`, không có gì được tạo, cart giữ nguyên):

| Trường hợp | Body |
|---|---|
| Cart rỗng / chưa có | `{"message": "Your cart is empty.", "errors": {"cart": [...]}}` |
| Sách đã `inactive` | `{"message": "...", "errors": {"items": ["\"Title\" (book 12) is no longer available."]}}` |
| Không đủ hàng | `{"message": "Insufficient stock", "errors": {"book_id": [...]}, "shortages": [{"book_id": 12, "requested": 3, "available": 1}]}` |
| Địa chỉ không hợp lệ | `errors.address_id` |

### GET /orders
Đơn của customer, mới nhất trước, kèm `items` và `address`. `per_page` 1–50 (mặc định 15), `page`.
Dùng `paginate()` giống `/books` để client có tổng số đơn và số trang.

### GET /orders/{id}
Chi tiết đơn kèm `items`, `address`, `payments`, `status_histories`.

### POST /orders/{id}/cancel
Body: `note` (tùy chọn). Chỉ hủy được khi đơn đang `pending`, `confirmed` hoặc `processing`.
Tồn kho đã reserve được hoàn lại, payment COD chuyển `failed`, ghi history. Trả về đơn đã cập nhật.

| Trường hợp | Kết quả |
|---|---|
| Đơn `shipped` / `delivered` | `422` `errors.status: ["The order can no longer be cancelled because it is shipped."]` |
| Đã hủy rồi | `422` `errors.status: ["The order is already cancelled."]` — tồn kho không bị hoàn hai lần |

### GET /orders/{id}/payments
Các payment của đơn (cũ nhất trước). Đơn của customer khác → `404`.

```json
{
  "data": [
    {"id": 1, "method": "cod", "amount": "855000.00", "status": "paid", "transaction_no": null, "paid_at": "2026-09-30T14:01:49+00:00", "created_at": "2026-09-30T14:01:49+00:00"}
  ]
}
```

| Trạng thái đơn | Payment COD |
|---|---|
| pending → shipped | `pending` |
| delivered | `paid` (`paid_at` = lúc giao), `orders.payment_status = paid` |
| cancelled | `failed` |

---

## Admin  🔒 (chỉ admin)

### PATCH /admin/orders/{id}/status
| Field | Rule |
|---|---|
| status | `confirmed` / `processing` / `shipped` / `delivered` / `cancelled` |
| note | nullable, ≤ 1000 |

Chỉ cho phép đi **từng bước**: `pending → confirmed → processing → shipped → delivered`; hủy được tới trước `shipped`.

- `delivered`: tồn kho `quantity -= n`, `reserved_quantity -= n` (bút toán `sale`); payment COD → `paid`, `payment_status = paid`.
- `cancelled`: `reserved_quantity -= n` (bút toán `release`); payment COD → `failed`.
- Nhảy bước hoặc lặp lại (ví dụ gọi `delivered` hai lần) → `422`, tồn kho không bị xử lý lần hai.
