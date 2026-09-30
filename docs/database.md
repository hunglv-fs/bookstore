# Database

PostgreSQL 17. Tài liệu này mô tả toàn bộ schema của MVP (Users/Roles, Book Catalog, Cart, Inventory, Orders, Payments).

## Nguyên tắc

- Ràng buộc đặt ở database (UNIQUE, FOREIGN KEY, NOT NULL, CHECK), không chỉ ở validation của Laravel.
  Những ràng buộc Laravel Blueprint không hỗ trợ (CHECK, partial index, `NULLS NOT DISTINCT`) được tạo bằng `DB::statement`.
- Foreign key mặc định `RESTRICT`. Chỉ dùng `CASCADE` cho các bảng con thuần túy mô tả cha (pivot, ảnh sách).
- Tiền tệ dùng `numeric(12,2)` — không dùng float. API trả giá dạng string (`"250000.00"`) để không mất độ chính xác.
- Trạng thái lưu `varchar` + `CHECK (... IN (...))` thay vì PostgreSQL ENUM type: thêm giá trị mới chỉ cần thay CHECK, không cần `ALTER TYPE`.
- Chỉ tạo index khi có query dùng tới. Index cho foreign key chỉ tạo khi có query lọc/join theo cột đó.

## Quan hệ

```text
roles 1──N users 1──1 customers 1──N customer_addresses

publishers 1──N books N──M authors      (book_authors)
                books N──M categories   (book_categories)
                books 1──N book_images
categories 1──N categories (parent_id)

customers 1──N carts 1──N cart_items N──1 books

books 1──1 inventory
books 1──N inventory_transactions

customers 1──N orders N──1 customer_addresses
orders 1──N order_items N──1 books
orders 1──N order_status_histories N──1 users (changed_by)
orders 1──N payments
```

## Bảng

### roles
| Cột | Kiểu | Ràng buộc |
|---|---|---|
| id | bigint | PK |
| name | varchar(50) | NOT NULL, UNIQUE |
| description | varchar | |

`admin` và `customer` được insert ngay trong migration: đây là dữ liệu tham chiếu mà ứng dụng cần để chạy (đăng ký gán role `customer`), nên không phụ thuộc vào seeder.

### users
| Cột | Kiểu | Ràng buộc |
|---|---|---|
| email | varchar | NOT NULL, UNIQUE (lưu lower-case) |
| password | varchar | NOT NULL (bcrypt) |
| phone, avatar | varchar | nullable |
| role_id | bigint | NOT NULL, FK → roles RESTRICT |
| is_active | boolean | NOT NULL, default true |

Không index `role_id`: MVP không có query "liệt kê user theo role", và role hầu như không bị xóa.

### customers
| Cột | Kiểu | Ràng buộc |
|---|---|---|
| user_id | bigint | NOT NULL, **UNIQUE**, FK → users RESTRICT |
| name, email, phone | | thông tin liên hệ, copy từ user khi đăng ký |

`UNIQUE(user_id)` vừa đảm bảo quan hệ 1:1, vừa là index cho truy vấn "customer của user đang đăng nhập".
User admin không có bản ghi customer.

### customer_addresses
| Cột | Kiểu | Ràng buộc |
|---|---|---|
| customer_id | bigint | NOT NULL, FK → customers RESTRICT |
| receiver_name, phone, address, ward, district, city | varchar | NOT NULL |
| is_default | boolean | NOT NULL, default false |

Địa chỉ **chỉ được tạo, không sửa, không xóa** (API không có PATCH/DELETE, FK từ `orders` là RESTRICT). Vì vậy `orders.address_id` luôn trỏ tới đúng địa chỉ lúc đặt hàng — tương đương một bản snapshot mà không phải nhân bản dữ liệu. Nếu sau này cho sửa địa chỉ, phải tạo bản ghi mới (versioning) hoặc snapshot địa chỉ vào `orders`.

### publishers / authors
`publishers.name` UNIQUE. `authors.name` không unique (hai tác giả có thể trùng tên).

### categories
| Cột | Kiểu | Ràng buộc |
|---|---|---|
| name | varchar(100) | NOT NULL |
| parent_id | bigint | nullable, FK → categories RESTRICT |

- `CHECK (parent_id IS NULL OR parent_id <> id)` — không tự làm cha của chính mình.
- `UNIQUE (parent_id, name) NULLS NOT DISTINCT` — tên không trùng giữa các category cùng cha, **kể cả các category gốc** (thiếu `NULLS NOT DISTINCT` thì PostgreSQL coi các NULL là khác nhau, nên hai root "Programming" vẫn lọt qua).
- Xóa category còn con hoặc còn sách → bị chặn (RESTRICT).

### books
| Cột | Kiểu | Ràng buộc |
|---|---|---|
| isbn | varchar(13) | NOT NULL, UNIQUE, CHECK định dạng ISBN-10 / ISBN-13 |
| title | varchar | NOT NULL |
| price | numeric(12,2) | NOT NULL, `CHECK (price >= 0)` |
| publisher_id | bigint | NOT NULL, FK → publishers RESTRICT |
| published_at | date | nullable |
| status | varchar(20) | NOT NULL, default `active`, `CHECK (status IN ('active','inactive'))` |

Không có cột `stock` — tồn kho nằm ở bảng `inventory` (Phase 4).
Sách không bao giờ bị xóa sau khi đã bán; để ẩn sách thì dùng `status = inactive`.

### book_authors / book_categories
Chỉ có hai cột khóa ngoại, **khóa chính kép** `(book_id, author_id)` / `(book_id, category_id)` — chính là ràng buộc UNIQUE mà spec yêu cầu.
`book_id` CASCADE (liên kết không có nghĩa khi sách không còn), `author_id` / `category_id` RESTRICT.

### book_images
| Cột | Kiểu | Ràng buộc |
|---|---|---|
| book_id | bigint | NOT NULL, FK → books CASCADE |
| image_url | varchar(2048) | NOT NULL |
| is_thumbnail | boolean | NOT NULL, default false |
| sort_order | smallint | NOT NULL, `CHECK (sort_order >= 0)` |

Partial unique index `(book_id) WHERE is_thumbnail` — mỗi sách tối đa **một** thumbnail.

### carts
| Cột | Kiểu | Ràng buộc |
|---|---|---|
| customer_id | bigint | NOT NULL, FK → customers RESTRICT |
| status | varchar(20) | NOT NULL, default `active`, `CHECK (status IN ('active','converted'))` |

Partial unique index `(customer_id) WHERE status = 'active'` — mỗi customer tối đa **một** cart active; cart `converted` được giữ lại làm lịch sử.

### cart_items
| Cột | Kiểu | Ràng buộc |
|---|---|---|
| cart_id | bigint | NOT NULL, FK → carts CASCADE |
| book_id | bigint | NOT NULL, FK → books RESTRICT |
| quantity | integer | NOT NULL, `CHECK (quantity > 0)` |

`UNIQUE (cart_id, book_id)` — một sách chỉ xuất hiện một dòng trong cart.

### inventory
Một kho duy nhất trong MVP → mỗi sách một dòng.

| Cột | Kiểu | Ràng buộc |
|---|---|---|
| book_id | bigint | NOT NULL, **UNIQUE**, FK → books RESTRICT |
| quantity | integer | NOT NULL, default 0, `CHECK (quantity >= 0)` — số lượng thực có trong kho |
| reserved_quantity | integer | NOT NULL, default 0, `CHECK (reserved_quantity >= 0)` — đã giữ cho đơn chưa giao |

- `CHECK (reserved_quantity <= quantity)` — tức `available >= 0`. Đây là **lớp chặn cuối cùng chống oversell**: kể cả khi code có lỗi hoặc race condition, database cũng không bao giờ chấp nhận giữ nhiều hơn số đang có.
- `available = quantity - reserved_quantity` được tính ra, **không lưu**.
- Mở rộng nhiều kho sau này: thêm `warehouse_id`, đổi unique thành `(warehouse_id, book_id)`.

### inventory_transactions
Sổ cái **chỉ ghi thêm** (không có `updated_at`), mọi thay đổi tồn kho đều có một dòng ở đây.

| Cột | Kiểu | Ràng buộc |
|---|---|---|
| book_id | bigint | NOT NULL, FK → books RESTRICT |
| transaction_type | varchar(20) | NOT NULL, `purchase` / `sale` / `reservation` / `release` / `adjustment` |
| quantity_change | integer | NOT NULL |
| reference_type, reference_id | varchar(50), bigint | nullable; cùng NULL hoặc cùng có giá trị |
| created_at | timestamp | NOT NULL, default now() |

`quantity_change` là thay đổi của **bộ đếm mà loại giao dịch tác động**:

| Type | Tác động | Dấu (CHECK) | Ví dụ |
|---|---|---|---|
| purchase | `quantity += n` | > 0 | Nhập 100 → `+100` |
| adjustment | `quantity += n` | ≠ 0 | Kiểm kê thiếu 2 → `-2` |
| reservation | `reserved_quantity += n` | > 0 | Đặt 2 cuốn → `+2` |
| release | `reserved_quantity -= n` | < 0 | Hủy đơn → `-2` |
| sale | `quantity -= n` **và** `reserved_quantity -= n` | < 0 | Giao hàng → `-2` |

Nhờ vậy luôn đối soát được:
`quantity = Σ(purchase, adjustment, sale)` và `reserved_quantity = Σ(reservation, release, sale)` (có test kiểm chứng).

Ràng buộc khác:
- `reservation` / `release` / `sale` bắt buộc có reference (đơn hàng).
- **Partial unique index `(reference_type, reference_id, book_id, transaction_type) WHERE transaction_type IN ('reservation','release','sale')`**: một đơn chỉ được reserve / release / sale mỗi cuốn sách **một lần**. Đây là bảo đảm idempotency ở tầng database — gọi lại API hủy/giao hàng (kể cả đồng thời) không thể trừ hay hoàn kho hai lần.

### orders
Dữ liệu tài chính: mọi FK tới/từ `orders`, `order_items`, `order_status_histories` là **RESTRICT** — không có đường nào xóa đơn hàng gián tiếp.

| Cột | Kiểu | Ràng buộc |
|---|---|---|
| order_no | varchar(20) | NOT NULL, UNIQUE — dạng `BK260930-J4VDJ4B5` (ngày + 8 ký tự ngẫu nhiên, bỏ 0/O/1/I) |
| customer_id | bigint | NOT NULL, FK → customers RESTRICT |
| address_id | bigint | NOT NULL, FK → customer_addresses RESTRICT |
| status | varchar(20) | `CHECK IN ('pending','confirmed','processing','shipped','delivered','cancelled')` |
| payment_status | varchar(20) | `CHECK IN ('unpaid','paid')` |
| shipping_fee, subtotal | numeric(12,2) | `CHECK (subtotal >= 0 AND shipping_fee >= 0)` |
| total_amount | numeric(12,2) | `CHECK (total_amount = subtotal + shipping_fee)` |
| note | text | nullable |
| ordered_at | timestamp | NOT NULL |

`shipping_fee` = 0 trong MVP (Advanced Shipping ngoài phạm vi).

### order_items
| Cột | Kiểu | Ràng buộc |
|---|---|---|
| order_id | bigint | NOT NULL, FK → orders RESTRICT |
| book_id | bigint | NOT NULL, FK → books RESTRICT |
| quantity | integer | `CHECK (quantity > 0)` |
| unit_price | numeric(12,2) | `CHECK (unit_price >= 0)` — **giá tại thời điểm mua**, không đổi khi `books.price` đổi |
| subtotal | numeric(12,2) | `CHECK (subtotal = unit_price * quantity)` |

`UNIQUE (order_id, book_id)` — mỗi sách một dòng (cart đã gộp theo sách).

### order_status_histories
| Cột | Kiểu | Ràng buộc |
|---|---|---|
| order_id | bigint | NOT NULL, FK → orders RESTRICT |
| old_status | varchar(20) | nullable — NULL chỉ ở dòng ghi nhận lúc tạo đơn |
| new_status | varchar(20) | NOT NULL |
| changed_by | bigint | nullable, FK → users RESTRICT |
| note | text | nullable |
| created_at | timestamp | NOT NULL, default now() |

CHECK giá trị trạng thái hợp lệ, và `old_status IS DISTINCT FROM new_status` (không ghi "đổi" sang chính nó).

### payments
| Cột | Kiểu | Ràng buộc |
|---|---|---|
| order_id | bigint | NOT NULL, FK → orders RESTRICT |
| method | varchar(20) | `CHECK IN ('cod','bank_transfer','credit_card','e_wallet')` — MVP chỉ dùng `cod` |
| amount | numeric(12,2) | `CHECK (amount >= 0)` — bằng `orders.total_amount` lúc checkout |
| status | varchar(20) | `CHECK IN ('pending','paid','failed')` |
| transaction_no | varchar(100) | nullable — mã giao dịch của payment gateway (COD không có) |
| paid_at | timestamp | nullable, `CHECK ((status = 'paid') = (paid_at IS NOT NULL))` |

- Partial unique index `(order_id) WHERE status IN ('pending','paid')` — mỗi đơn tối đa **một** payment còn hiệu lực; các lần thử thất bại (`failed`) được giữ lại làm lịch sử. Sẵn sàng cho gateway: thanh toán thất bại rồi thử lại.
- `UNIQUE (method, transaction_no)` — một giao dịch của gateway chỉ được ghi nhận một lần (chống xử lý callback lặp). NULL không bị so sánh nên COD không vướng.
- CHECK `method` đã chấp nhận sẵn các phương thức gateway, nên thêm gateway không cần migration.

Vòng đời payment COD:

| Sự kiện | payments.status | orders.payment_status |
|---|---|---|
| Checkout | `pending` (amount = total_amount) | `unpaid` |
| Đơn `delivered` (thu tiền mặt) | `paid`, ghi `paid_at` | `paid` |
| Đơn `cancelled` | `failed` (không bao giờ thu) | `unpaid` |

## Index và query sử dụng

| Index | Phục vụ query |
|---|---|
| `users_email_unique` | Đăng nhập: `WHERE email = ?`; chặn trùng email |
| `customers_user_id_unique` | Lấy customer của user hiện tại |
| `customer_addresses_customer_id_index` | Danh sách địa chỉ của customer |
| `customer_addresses_one_default_per_customer` (partial unique) | Đảm bảo ≤ 1 địa chỉ mặc định; tìm địa chỉ mặc định |
| `books_isbn_unique` | Chặn trùng ISBN; tìm theo ISBN (`keyword` trùng khớp ISBN) |
| `books_publisher_id_index` | `GET /api/books?publisher_id=` |
| `book_authors_pkey (book_id, author_id)` | Eager-load tác giả của các sách trên trang (`WHERE book_id IN (...)`) |
| `book_authors_author_id_index` | `GET /api/books?author_id=` (`EXISTS ... WHERE author_id = ?`) |
| `book_categories_pkey (book_id, category_id)` | Eager-load category của sách; `EXISTS` khi lọc theo category |
| `book_categories_category_id_index` | `GET /api/books?category_id=` |
| `categories_parent_id_index` | Lấy danh mục con; CTE đệ quy tìm category con cháu |
| `book_images_book_id_sort_order_index` | Ảnh của sách theo thứ tự hiển thị; eager-load thumbnail |
| `carts_one_active_per_customer` (partial unique) | Tìm cart active của customer; đảm bảo ≤ 1 cart active |
| `cart_items_cart_id_book_id_unique` | Lấy các item của cart (cột đầu `cart_id`); tìm dòng của một sách khi cộng dồn; chặn trùng |
| `inventory_book_id_unique` | `SELECT ... FOR UPDATE` các dòng inventory của đơn hàng; eager-load tồn kho trong catalog |
| `inventory_transactions_book_id_created_at_index` | Lịch sử nhập/xuất của một sách theo thời gian |
| `inventory_transactions_once_per_reference` (partial unique) | Idempotency; tìm các reservation của một đơn khi release/sale (cột đầu `reference_type, reference_id`) |
| `orders_order_no_unique` | Chặn trùng mã đơn; tra cứu đơn theo mã (CSKH) |
| `orders_customer_id_ordered_at_index` | `GET /api/orders`: `WHERE customer_id = ? ORDER BY ordered_at DESC` — lọc và sắp xếp bằng một index |
| `order_items_order_id_book_id_unique` | Lấy items của đơn (cột đầu `order_id`); chặn trùng sách trong đơn |
| `order_status_histories_order_id_created_at_index` | Timeline trạng thái của một đơn |
| `payments_order_id_index` | `GET /api/orders/{id}/payments`; eager-load payment trong chi tiết đơn (gồm cả payment `failed`, nên partial index bên dưới không thay được) |
| `payments_one_live_per_order` (partial unique) | Bảo đảm ≤ 1 payment pending/paid; tìm payment đang hiệu lực khi giao/hủy đơn |
| `payments_method_transaction_no_unique` | Chặn ghi nhận trùng giao dịch gateway; tra cứu theo mã giao dịch khi gateway callback |

### Index đã cân nhắc nhưng chưa tạo

- **`orders.status`, `orders.created_at`**: MVP không có query nào lọc/sắp xếp đơn theo trạng thái hay ngày trên toàn bảng (chưa có danh sách đơn cho admin; admin đổi trạng thái theo id). Khi có trang quản trị, thêm index theo đúng query, ví dụ `(status, ordered_at)`.
- **`orders.customer_id` riêng**: thừa — composite `(customer_id, ordered_at)` có `customer_id` là cột đầu.
- **`order_items.book_id`**: chưa có query "đơn nào chứa sách X" (báo cáo bán hàng ngoài MVP); sách không bị xóa nên không cần index cho kiểm tra FK.

- **`cart_items.cart_id` riêng lẻ**: thừa, vì unique index `(cart_id, book_id)` có `cart_id` là cột đầu nên phục vụ được mọi query theo `cart_id`.
- **`inventory_transactions (reference_type, reference_id)` riêng**: thừa — partial unique index ở trên đã bắt đầu bằng hai cột này, và PostgreSQL dùng được nó cho query `transaction_type = 'reservation'` vì điều kiện này nằm trong `IN (...)` của index.
- **`cart_items.book_id`**: không có query nào tìm cart theo sách; sách cũng không bị xóa nên không cần index để kiểm tra FK.

- **`books.status`**: chỉ có 2 giá trị và phần lớn sách là `active`, nên index riêng trên cột này gần như không lọc được gì — planner vẫn sẽ quét tuần tự. Nếu catalog lớn và tỉ lệ `inactive` đáng kể, nên thay bằng partial index cho đúng query listing, ví dụ `(published_at DESC, id DESC) WHERE status = 'active'`.
- **Tìm kiếm theo `keyword`** (`title ILIKE '%...%'`): wildcard ở đầu không dùng được B-tree. Với vài trăm/vài nghìn sách, quét tuần tự vẫn nhanh. Khi cần mở rộng: extension `pg_trgm` + GIN index, hoặc full-text search (đề xuất ở Phase 4 mở rộng).
- **Index cho sort theo `price` / `published_at`**: chưa cần ở quy mô MVP; thêm khi `EXPLAIN ANALYZE` cho thấy sort là nút cổ chai.
