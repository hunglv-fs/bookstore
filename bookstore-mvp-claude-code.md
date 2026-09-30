# Bookstore MVP --- Laravel 12 + PostgreSQL 17

## Role

Bạn là Senior Backend Engineer chuyên về:

-   PHP 8.4
-   Laravel 12
-   PostgreSQL 17
-   REST API
-   Database design
-   Transactions & concurrency
-   Automated testing
-   Docker

Hãy xây dựng **MVP hệ thống bán sách online** bằng Laravel + PostgreSQL.

## Mục tiêu

Ưu tiên:

1.  MVP chạy được end-to-end.
2.  Database design đúng.
3.  Business flow rõ ràng.
4.  Code dễ mở rộng.
5.  Có test cho các nghiệp vụ quan trọng.

**Không over-engineering.**

Không triển khai tất cả tính năng nâng cao ngay từ đầu.

Sau khi MVP hoàn thành và test pass, mới đề xuất Phase 2.

------------------------------------------------------------------------

# 1. Technology Stack

Bắt buộc:

-   PHP 8.4
-   Laravel 12
-   PostgreSQL 17
-   Laravel Sanctum
-   PHPUnit hoặc Pest
-   Docker Compose

Database bắt buộc là PostgreSQL.

Không sử dụng MySQL.

------------------------------------------------------------------------

# 2. MVP Scope

MVP chỉ bao gồm:

-   Authentication
-   Users / Roles
-   Customers
-   Books
-   Authors
-   Publishers
-   Categories
-   Book Images
-   Shopping Cart
-   Orders
-   Order Items
-   Inventory
-   Payments
-   Order Status History

## Chưa triển khai trong MVP

Không implement:

-   Wishlist
-   Coupons
-   Refunds
-   Advanced Shipping
-   Reviews
-   Audit Logs
-   Multiple Warehouses
-   Redis
-   Recommendation system
-   Search engine
-   Elasticsearch
-   Notifications
-   Admin dashboard
-   Frontend

Nếu có nhu cầu mở rộng, chỉ thiết kế sao cho database có thể mở rộng về
sau.

------------------------------------------------------------------------

# 3. Architecture

Sử dụng Laravel convention.

``` text
app/
├── Models/
├── Http/
│   ├── Controllers/
│   ├── Requests/
│   └── Resources/
├── Services/
├── Policies/
└── Exceptions/
```

Controller phải mỏng.

Business logic quan trọng đặt trong Service hoặc Action.

Không tạo abstraction không cần thiết.

Ví dụ có thể tạo:

-   CheckoutService
-   InventoryService
-   OrderService

Chỉ tạo khi thực sự có business logic.

------------------------------------------------------------------------

# 4. Database

## roles

``` text
id
name
description
created_at
updated_at
```

Roles MVP:

-   admin
-   customer

## users

``` text
id
name
email
password
phone
avatar
role_id
is_active
created_at
updated_at
```

Quan hệ:

``` text
roles 1:N users
```

## customers

``` text
id
user_id
name
email
phone
created_at
updated_at
```

Quan hệ:

``` text
users 1:1 customers
```

## customer_addresses

``` text
id
customer_id
receiver_name
phone
address
ward
district
city
is_default
created_at
updated_at
```

Quan hệ:

``` text
customers 1:N customer_addresses
```

------------------------------------------------------------------------

# 5. Book Catalog

## publishers

``` text
id
name
address
website
created_at
updated_at
```

## authors

``` text
id
name
biography
created_at
updated_at
```

## categories

``` text
id
name
description
parent_id
created_at
updated_at
```

Cho phép category hierarchy.

Ví dụ:

``` text
Programming
├── Backend
├── Frontend
└── Database
```

## books

``` text
id
isbn
title
description
price
publisher_id
published_at
status
created_at
updated_at
```

Không lưu `stock` trong `books`.

`isbn` phải unique.

Status:

-   active
-   inactive

## book_authors

``` text
book_id
author_id
```

Unique:

``` text
UNIQUE(book_id, author_id)
```

## book_categories

``` text
book_id
category_id
```

Unique:

``` text
UNIQUE(book_id, category_id)
```

## book_images

``` text
id
book_id
image_url
is_thumbnail
sort_order
created_at
updated_at
```

------------------------------------------------------------------------

# 6. Inventory MVP

MVP chỉ có **một kho duy nhất**.

Không tạo `warehouses` trong MVP.

## inventory

``` text
id
book_id
quantity
reserved_quantity
created_at
updated_at
```

Unique:

``` text
UNIQUE(book_id)
```

Available stock:

``` text
available = quantity - reserved_quantity
```

Không cần lưu `available_quantity`.

## inventory_transactions

``` text
id
book_id
transaction_type
quantity_change
reference_type
reference_id
created_at
```

Transaction type:

-   purchase
-   sale
-   reservation
-   release
-   adjustment

Ví dụ:

Nhập 100:

``` text
+100
```

Đặt hàng 2:

``` text
reserved_quantity += 2
```

Đơn hàng hoàn tất:

``` text
quantity -= 2
reserved_quantity -= 2
```

Đơn hàng bị hủy:

``` text
reserved_quantity -= 2
```

------------------------------------------------------------------------

# 7. Shopping Cart

## carts

``` text
id
customer_id
status
created_at
updated_at
```

Status:

-   active
-   converted

## cart_items

``` text
id
cart_id
book_id
quantity
created_at
updated_at
```

Constraint:

``` text
UNIQUE(cart_id, book_id)
```

------------------------------------------------------------------------

# 8. Orders

## orders

``` text
id
order_no
customer_id
address_id
status
payment_status
shipping_fee
subtotal
total_amount
note
ordered_at
created_at
updated_at
```

Order status:

-   pending
-   confirmed
-   processing
-   shipped
-   delivered
-   cancelled

Payment status:

-   unpaid
-   paid

MVP chỉ cần hỗ trợ:

-   COD

Có thể chuẩn bị structure để sau này thêm:

-   bank_transfer
-   credit_card
-   e_wallet

------------------------------------------------------------------------

# 9. Order Items

## order_items

``` text
id
order_id
book_id
quantity
unit_price
subtotal
created_at
updated_at
```

Đặc biệt:

`unit_price` phải là giá tại thời điểm mua.

Ví dụ:

``` text
books.price = 100000
```

Order:

``` text
unit_price = 100000
quantity = 2
subtotal = 200000
```

Sau đó giá sách thay đổi thành:

``` text
150000
```

Order cũ vẫn phải giữ:

``` text
100000 × 2
```

------------------------------------------------------------------------

# 10. Order Status History

## order_status_histories

``` text
id
order_id
old_status
new_status
changed_by
note
created_at
```

Mỗi lần order status thay đổi phải tạo history.

Ví dụ:

``` text
pending
 ↓
confirmed
 ↓
processing
 ↓
shipped
 ↓
delivered
```

------------------------------------------------------------------------

# 11. Payments

## payments

``` text
id
order_id
method
amount
status
transaction_no
paid_at
created_at
updated_at
```

MVP:

``` text
method = cod
```

Payment status:

-   pending
-   paid
-   failed

Thiết kế để sau này có thể thêm payment gateway.

------------------------------------------------------------------------

# 12. Checkout Flow

Đây là business flow quan trọng nhất của MVP.

Flow:

``` text
Customer
   ↓
Cart
   ↓
Validate cart
   ↓
Validate book status
   ↓
Check inventory
   ↓
Calculate subtotal
   ↓
Create order
   ↓
Create order items
   ↓
Reserve inventory
   ↓
Create payment
   ↓
Mark cart as converted
   ↓
Clear cart items
```

Toàn bộ flow phải nằm trong:

``` php
DB::transaction(...)
```

Nếu một bước thất bại:

``` text
ROLLBACK
```

Không được xảy ra tình trạng:

``` text
Order created
nhưng inventory chưa reserve
```

hoặc:

``` text
Inventory đã reserve
nhưng order không tồn tại
```

------------------------------------------------------------------------

# 13. Concurrency

Phải xử lý race condition.

Ví dụ:

``` text
Stock = 1

Customer A checkout quantity = 1
Customer B checkout quantity = 1
```

Không được để cả hai order thành công.

Sử dụng PostgreSQL transaction và row-level locking.

Ví dụ có thể sử dụng:

``` php
->lockForUpdate()
```

Nhưng không được sử dụng một cách máy móc.

Hãy giải thích:

-   transaction bắt đầu ở đâu?
-   row nào bị lock?
-   khi nào kiểm tra stock?
-   khi nào tăng `reserved_quantity`?
-   khi nào commit?

------------------------------------------------------------------------

# 14. Cancel Order

MVP phải hỗ trợ:

``` text
POST /api/orders/{order}/cancel
```

Chỉ cho phép cancel khi order chưa:

-   shipped
-   delivered

Khi cancel:

``` text
order.status = cancelled

reserved_quantity -= order_item.quantity
```

Tạo inventory transaction:

``` text
release
```

Tạo order status history.

Tất cả phải nằm trong transaction.

------------------------------------------------------------------------

# 15. Order Completion

Khi order chuyển sang:

``` text
delivered
```

Inventory phải được xử lý:

``` text
quantity -= reserved_quantity
reserved_quantity -= reserved_quantity
```

Tạo inventory transaction:

``` text
sale
```

Phải đảm bảo logic này không chạy hai lần nếu API được gọi lặp lại.

------------------------------------------------------------------------

# 16. REST API

## Authentication

``` text
POST /api/register
POST /api/login
POST /api/logout
GET  /api/me
```

## Books

``` text
GET /api/books
GET /api/books/{id}
```

## Categories

``` text
GET /api/categories
GET /api/categories/{id}
```

## Cart

``` text
GET    /api/cart
POST   /api/cart/items
PATCH  /api/cart/items/{id}
DELETE /api/cart/items/{id}
```

## Orders

``` text
POST /api/orders
GET  /api/orders
GET  /api/orders/{id}
POST /api/orders/{id}/cancel
```

## Payments

``` text
GET /api/orders/{id}/payments
```

Reviews chưa nằm trong MVP.

------------------------------------------------------------------------

# 17. Book Listing

API:

``` text
GET /api/books
```

Hỗ trợ:

``` text
keyword
category_id
author_id
publisher_id
min_price
max_price
sort
per_page
```

Ví dụ:

``` text
GET /api/books?keyword=laravel&category_id=2&sort=price_asc
```

Filtering phải thực hiện tại PostgreSQL.

Không load toàn bộ books vào PHP rồi filter.

------------------------------------------------------------------------

# 18. Pagination

Sử dụng:

``` php
paginate()
```

hoặc:

``` php
cursorPaginate()
```

Phải giải thích lựa chọn.

Không sử dụng:

``` php
Book::all()
```

cho endpoint listing.

------------------------------------------------------------------------

# 19. Validation

Sử dụng Form Request.

Ví dụ:

-   StoreCartItemRequest
-   UpdateCartItemRequest
-   CheckoutRequest
-   LoginRequest
-   RegisterRequest

Validate:

-   quantity \> 0
-   price \>= 0
-   valid email
-   valid address
-   valid book

------------------------------------------------------------------------

# 20. Authorization

Customer chỉ được:

-   xem cart của mình
-   sửa cart của mình
-   xem order của mình
-   cancel order của mình

Không được xem order của customer khác.

Admin có quyền quản lý:

-   books
-   authors
-   publishers
-   categories
-   inventory
-   orders

Sử dụng Policy / Gate phù hợp.

------------------------------------------------------------------------

# 21. API Response

Sử dụng Laravel API Resources.

Response format nên thống nhất.

Ví dụ:

``` json
{
    "data": {
        "id": 1,
        "title": "Clean Code",
        "price": "250000.00"
    }
}
```

Error:

``` json
{
    "message": "Insufficient stock",
    "errors": {
        "book_id": [
            "The requested quantity is not available."
        ]
    }
}
```

Không expose stack trace hoặc database information.

------------------------------------------------------------------------

# 22. Database Constraints

Ưu tiên constraint ở database.

Ít nhất:

-   UNIQUE
-   FOREIGN KEY
-   NOT NULL
-   CHECK

Ví dụ:

``` text
books.price >= 0
inventory.quantity >= 0
inventory.reserved_quantity >= 0
order_items.quantity > 0
order_items.unit_price >= 0
```

Không dùng cascade delete bừa bãi.

Đặc biệt bảo vệ:

-   orders
-   order_items
-   payments
-   inventory_transactions

------------------------------------------------------------------------

# 23. Index

Tạo index có lý do.

Ít nhất xem xét:

``` text
books.isbn
books.publisher_id
books.status

book_authors.book_id
book_authors.author_id

book_categories.book_id
book_categories.category_id

orders.customer_id
orders.status
orders.created_at
orders.order_no

order_items.order_id
order_items.book_id

inventory.book_id

payments.order_id

customer_addresses.customer_id
cart_items.cart_id
```

Không tạo index máy móc.

Trong README giải thích index nào phục vụ query nào.

------------------------------------------------------------------------

# 24. Testing

Viết test cho các flow quan trọng.

## Authentication

-   register
-   login
-   logout
-   invalid login

## Cart

-   add item
-   update quantity
-   remove item
-   duplicate book

## Checkout

-   successful checkout
-   empty cart
-   inactive book
-   insufficient stock

## Inventory

-   reserve stock
-   release stock
-   complete sale
-   prevent overselling

## Orders

-   create order
-   get own order
-   cannot access another user's order
-   cancel order
-   cannot cancel shipped order

## Payment

-   COD payment created
-   payment amount matches order

------------------------------------------------------------------------

# 25. Seed Data

Tạo realistic seed data:

``` text
2 roles
10 users
10 customers
10 authors
5 publishers
10 categories
100 books
book authors
book categories
book images
100 inventory records
```

Có thể tạo thêm orders để test API.

Không cần tạo hàng triệu records trong MVP.

------------------------------------------------------------------------

# 26. Docker

Tạo:

``` text
docker-compose.yml
```

MVP chỉ cần:

``` text
app
postgres
```

Chưa cần Redis.

------------------------------------------------------------------------

# 27. Documentation

Tạo:

``` text
README.md
docs/api.md
docs/database.md
docs/architecture.md
```

## README

Hướng dẫn:

``` bash
docker compose up -d
composer install
php artisan key:generate
php artisan migrate --seed
php artisan test
```

## database.md

Mô tả:

-   tables
-   relationships
-   constraints
-   indexes

## architecture.md

Giải thích:

-   Controller
-   Request
-   Service
-   Model
-   Database
-   Transaction

## api.md

Document các endpoint MVP.

------------------------------------------------------------------------

# 28. Implementation Phases

Không cố implement toàn bộ hệ thống một lần.

## Phase 1 --- Foundation

Implement:

-   Laravel setup
-   Docker
-   PostgreSQL
-   Users
-   Roles
-   Authentication
-   Sanctum

Chạy test.

------------------------------------------------------------------------

## Phase 2 --- Book Catalog

Implement:

-   Books
-   Authors
-   Publishers
-   Categories
-   Book Images

Tạo seed data.

Implement:

``` text
GET /api/books
GET /api/books/{id}
GET /api/categories
```

Chạy test.

------------------------------------------------------------------------

## Phase 3 --- Cart

Implement:

-   Cart
-   Cart Items

API:

``` text
GET
POST
PATCH
DELETE
```

Chạy test.

------------------------------------------------------------------------

## Phase 4 --- Inventory

Implement:

-   inventory
-   inventory_transactions

Implement:

-   reserve
-   release
-   complete sale

Viết test concurrency nếu có thể.

------------------------------------------------------------------------

## Phase 5 --- Orders + Checkout

Implement:

-   orders
-   order_items
-   order_status_histories

Implement:

-   Checkout
-   Cancel Order
-   Order Listing
-   Order Detail

Đây là phase quan trọng nhất.

Phải đảm bảo transaction và concurrency đúng.

------------------------------------------------------------------------

## Phase 6 --- Payments

Implement:

-   payments

Chỉ cần COD.

Không tích hợp payment gateway trong MVP.

------------------------------------------------------------------------

## Phase 7 --- Testing + Documentation

Chạy:

``` bash
php artisan test
```

Fix toàn bộ test failure.

Kiểm tra:

-   migration
-   seed
-   API
-   authorization
-   transaction
-   inventory
-   concurrency

Hoàn thiện documentation.

------------------------------------------------------------------------

# 29. Definition of Done

MVP chỉ được coi là hoàn thành khi:

``` text
[ ] Docker chạy được
[ ] PostgreSQL chạy được
[ ] Migration chạy thành công
[ ] Seeder chạy thành công
[ ] User đăng ký được
[ ] User login được
[ ] Customer xem books được
[ ] Customer thêm sách vào cart được
[ ] Customer update cart được
[ ] Customer checkout được
[ ] Inventory được reserve
[ ] Không oversell khi concurrent checkout
[ ] Order được tạo đúng
[ ] Order item lưu unit_price
[ ] Payment COD được tạo
[ ] Customer xem order của mình
[ ] Customer không xem được order người khác
[ ] Customer cancel order hợp lệ
[ ] Inventory được release khi cancel
[ ] Order delivered xử lý inventory đúng
[ ] Order status history được tạo
[ ] Test pass
[ ] README hoàn chỉnh
```

------------------------------------------------------------------------

# 30. Sau khi MVP hoàn thành

**Không tự động implement Phase 2.**

Chỉ báo cáo đề xuất mở rộng:

## Phase 2

-   Wishlist
-   Reviews
-   Coupons
-   Shipping
-   Refunds

## Phase 3

-   Redis
-   Queue
-   Notifications
-   Multiple warehouses

## Phase 4

-   Search optimization
-   PostgreSQL full-text search
-   Performance tuning
-   Load testing
-   Observability

Chờ tôi xác nhận trước khi triển khai các phase này.

------------------------------------------------------------------------

# 31. Cách làm việc với tôi

Khi bắt đầu:

1.  Kiểm tra project hiện tại.
2.  Không overwrite code một cách tùy tiện.
3.  Phân tích structure hiện tại.
4.  Đưa ra kế hoạch Phase 1.
5.  Implement only Phase 1.
6.  Chạy test.
7.  Báo cáo kết quả.
8.  Chờ xác nhận rồi mới sang Phase 2.

Sau mỗi phase báo cáo:

``` text
Implemented:
- ...

Database:
- ...

API:
- ...

Tests:
- ...

Issues:
- ...

Design decisions:
- ...

Next phase:
- ...
```

Nếu phát hiện vấn đề về database design hoặc business rule, hãy dừng
trước khi tự quyết định thay đổi requirement.

------------------------------------------------------------------------

# 32. Important Engineering Principles

Ưu tiên:

``` text
Correctness
>
Data consistency
>
Transaction safety
>
Security
>
Maintainability
>
Performance
>
Complexity
```

Đừng tối ưu premature.

Đừng tạo microservices.

Đừng tạo event-driven architecture phức tạp cho MVP.

Đừng dùng Redis nếu PostgreSQL/Laravel đã đủ.

Đừng tạo Repository pattern chỉ vì "best practice".

Đừng thêm package nếu Laravel native đã giải quyết được vấn đề.

MVP phải đơn giản nhưng có nền tảng tốt để mở rộng.

------------------------------------------------------------------------

# 33. Bắt đầu ngay

Trước tiên:

``` text
1. Inspect existing project.
2. Identify current Laravel/PHP/PostgreSQL setup.
3. Check what already exists.
4. Propose Phase 1 implementation plan.
5. Implement only Phase 1.
6. Run tests.
7. Report result.
```

**Không implement Phase 2 trở đi cho đến khi tôi xác nhận.**
