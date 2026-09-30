# Kiến trúc

Laravel 12 monolith, chỉ có REST API. Theo convention của Laravel — không Repository, không event, không package ngoài Sanctum.
Tài liệu mô tả MVP hoàn chỉnh.

```text
HTTP request
  → route (routes/api.php)          middleware auth:sanctum, active, role:customer, can:...
  → Form Request                    validate (lỗi → 422)
  → Controller                      mỏng: gọi 1 method của service, trả về Resource
  → Service                         business rule, DB::transaction, row lock
  → Model / Eloquent                relation, cast, query scope
  → PostgreSQL                      constraint là bảo đảm cuối cùng
  ← API Resource                    JSON thống nhất ({"data": ...})
```

## Các tầng

| Tầng | Vị trí | Trách nhiệm |
|---|---|---|
| Route | `routes/api.php` | URL → controller. Nhóm route gắn xác thực (`auth:sanctum`), chặn tài khoản bị khóa (`active`), role (`role:customer`), quyền trên từng bản ghi (`->can('update', 'item')`). Id trên route chỉ nhận chữ số → id sai định dạng là 404, không thành lỗi ép kiểu của PostgreSQL. |
| Form Request | `app/Http/Requests` | Validate và chuẩn hóa input (ví dụ lower-case email). Controller chỉ nhận dữ liệu đã validate. |
| Controller | `app/Http/Controllers/Api` | Mỏng: lấy customer hiện tại, gọi service hoặc query scope, bọc kết quả vào Resource. |
| Service | `app/Services` | Chỉ tạo khi có business logic thật: `AuthService` (tạo user + customer nguyên tử), `CartService` (gộp dòng, khóa cart), `InventoryService` (reserve / release / sale), `CheckoutService` (cart → order), `OrderService` (state machine trạng thái đơn + tác động tồn kho và payment), `PaymentService` (payment COD), `AddressService` (địa chỉ mặc định). |
| Policy | `app/Policies` | Quyền sở hữu bản ghi. `CartItemPolicy`, `OrderPolicy` từ chối bằng **404** (`denyAsNotFound`) để không lộ id bản ghi của customer khác. Role (`customer` / `admin`) kiểm tra bằng middleware `role:`. |
| Model | `app/Models` | Relation, enum cast, query scope (`Book::filter()`, `Book::sortBy()`, `Cart::active()`). |
| Resource | `app/Http/Resources` | Định dạng response. Tiền trả về dạng string thập phân. |
| Exception | `app/Exceptions`, `bootstrap/app.php` | `InsufficientStockException` → 422 kèm chi tiết. Mọi lỗi 4xx của API chỉ gồm `{"message"}`; lỗi 500 ẩn chi tiết khi `APP_DEBUG=false`. |
| Database | `database/migrations` | UNIQUE / FK / CHECK / partial index — giữ bất biến kể cả khi code ứng dụng sai. |

## Transaction và locking

Thứ tự ưu tiên: đúng đắn > nhất quán dữ liệu > an toàn transaction.

### Quy tắc chung

1. **Khóa trước khi quyết định.** Dòng dữ liệu mà một quyết định phụ thuộc vào (ví dụ "còn đủ hàng không?") được đọc bằng `SELECT ... FOR UPDATE`, để không ai sửa được nó giữa lúc kiểm tra và lúc ghi. Lock giữ tới khi transaction commit.
2. **Khóa theo thứ tự cố định.** Khi khóa nhiều dòng, luôn khóa theo `book_id` tăng dần. Hai transaction cần sách {A, B} và {B, A} sẽ xếp hàng chờ nhau thay vì deadlock (có test kiểm chứng).
3. **`DB::transaction()` lồng nhau = savepoint.** Method của service chạy độc lập được, nhưng khi caller đã mở transaction (checkout, hủy đơn) thì nó nhập vào transaction đó: lock kéo dài tới khi caller commit, và dữ liệu nó ghi bị rollback nếu caller thất bại.
4. **Database là tuyến phòng thủ cuối.** Constraint như `CHECK (reserved_quantity <= quantity)` và unique index idempotency biến một lần code quên kiểm tra thành câu lệnh bị từ chối, thay vì dữ liệu hỏng.

Mức isolation mặc định của PostgreSQL là READ COMMITTED: mỗi câu lệnh thấy dữ liệu đã commit trước khi câu lệnh bắt đầu; `FOR UPDATE` chờ transaction đang ghi xong rồi kiểm tra lại điều kiện `WHERE` trên phiên bản mới nhất của dòng.

### Cart (`CartService`)

Mọi thay đổi cart đều bắt đầu bằng `SELECT ... FROM carts WHERE id/customer AND status = 'active' FOR UPDATE`.
Nhờ vậy các thay đổi trên cùng một cart chạy tuần tự với nhau và với checkout (checkout khóa đúng dòng cart đó trước khi chuyển nó sang `converted`). Nếu checkout chuyển cart trong lúc một request đang chờ lock, điều kiện `status = 'active'` được kiểm tra lại sẽ không còn khớp → request đó làm việc trên cart active mới, hoặc trả 404 nếu là item của cart cũ.
Cart active được tạo khi cần bằng `firstOrCreate()`: insert trong savepoint, gặp unique violation thì SELECT lại — nhiều request đầu tiên đồng thời vẫn chỉ tạo một cart (partial unique index `carts_one_active_per_customer`).

### Inventory (`InventoryService`)

| Thao tác | Dòng bị khóa | Kiểm tra | Ghi |
|---|---|---|---|
| `reserve(items, ref)` | các dòng `inventory` của mọi sách trong đơn, theo `book_id` tăng dần | Sau khi khóa: `quantity - reserved_quantity >= requested` với **mọi** sách; thiếu bất kỳ sách nào → `InsufficientStockException` liệt kê tất cả sách thiếu, không ghi gì | `reserved_quantity += n` từng sách + bút toán `reservation` |
| `release(ref)` | các dòng `inventory` của những sách đã reserve | Sau khi khóa: reference chưa có `release`/`sale` | `reserved_quantity -= n`, bút toán `release` |
| `completeSale(ref)` | như trên | như trên | `quantity -= n`, `reserved_quantity -= n`, bút toán `sale` |
| `receive` / `adjust` | dòng của sách đó | `adjust`: quantity mới phải `>= reserved_quantity` | `quantity += n`, bút toán `purchase` / `adjustment` |

`release` và `completeSale` không nhận số lượng từ caller: chúng đọc lại chính các bút toán `reservation` của reference, nên chỉ có thể hoàn hoặc chốt đúng số đã giữ.

**Idempotency có ba lớp:**
1. Kiểm tra trạng thái đơn hàng (Phase 5).
2. Kiểm tra sổ cái *sau khi* đã lấy lock — request trùng chạy đồng thời thì hoặc đã commit (và nhìn thấy được), hoặc đang chờ lock.
3. Partial unique index `inventory_transactions_once_per_reference` — database từ chối `release`/`sale` lần hai cho cùng đơn và cùng sách.

### Ví dụ race condition: cuốn sách cuối cùng

```text
tồn kho: quantity = 1, reserved = 0

A: BEGIN                                   B: BEGIN
A: SELECT inventory ... FOR UPDATE  (khóa)
                                           B: SELECT inventory ... FOR UPDATE  → chờ A
A: available = 1 ≥ 1 ✓
A: UPDATE reserved_quantity = 1
A: COMMIT  (nhả lock)
                                           B: có lock, đọc lại: quantity 1, reserved 1
                                           B: available = 0 < 1 → InsufficientStockException → ROLLBACK (422)
```

`tests/Feature/Concurrency/InventoryConcurrencyTest` tái hiện tình huống này bằng các process chạy song song thật: 2 người mua 1 cuốn, 12 người mua 5 cuốn, và khóa theo thứ tự ngược nhau. Khi tạm bỏ `lockForUpdate()`, cả hai test oversell đều fail — CHECK constraint vẫn chặn được oversell, nhưng request thua nhận lỗi 500 thay vì 422 rõ ràng.

## Checkout (`CheckoutService`)

Toàn bộ flow nằm trong **một** `DB::transaction()`:

```text
BEGIN
 1. SELECT carts ... WHERE customer AND status='active' FOR UPDATE   ← khóa cart
    cart không có / rỗng                → 422 "Your cart is empty."     → ROLLBACK
 2. SELECT books ... ORDER BY id FOR SHARE                           ← khóa chia sẻ sách
    sách inactive                        → 422 errors.items             → ROLLBACK
 3. Tính unit_price (giá hiện tại của sách), subtotal từng dòng, tổng (bcmath)
 4. INSERT orders (pending, unpaid)
 5. INSERT order_items (unit_price = giá lúc mua)
 6. InventoryService::reserve()
      SELECT inventory ... ORDER BY book_id FOR UPDATE               ← khóa tồn kho
      kiểm tra available cho mọi sách     → 422 Insufficient stock      → ROLLBACK (xóa luôn order ở bước 4)
      UPDATE reserved_quantity += n; INSERT inventory_transactions (reservation)
 7. INSERT payments (cod, amount = total_amount, pending)
 8. INSERT order_status_histories (NULL → pending)
 9. DELETE cart_items; UPDATE carts SET status='converted'
COMMIT                                                               ← nhả mọi lock
```

Vì chỉ có một transaction, **không thể** xảy ra "order đã tạo nhưng chưa reserve" hay "đã reserve nhưng không có order": một bước lỗi thì mọi bước trước đó cũng bị rollback (có test cho trường hợp thiếu hàng: order đã insert ở bước 4 biến mất, cart giữ nguyên).

### Trả lời các câu hỏi ở mục 13 của spec

| Câu hỏi | Trả lời |
|---|---|
| Transaction bắt đầu ở đâu? | `DB::transaction()` ở đầu `CheckoutService::checkout()`, **trước** khi đọc cart. Các `DB::transaction()` bên trong `InventoryService` / `OrderService` chỉ là savepoint của transaction này. |
| Row nào bị lock? | (1) dòng `carts` active của customer — `FOR UPDATE`; (2) các dòng `books` trong cart — `FOR SHARE` (nhiều checkout đọc cùng lúc được, nhưng không ai đổi giá/trạng thái sách được cho tới khi commit); (3) các dòng `inventory` của những sách đó — `FOR UPDATE`, theo `book_id` tăng dần. Không khóa cả bảng. |
| Khi nào kiểm tra stock? | Trong `reserve()`, **ngay sau khi** đã lock các dòng inventory (bước 6) — đọc `quantity - reserved_quantity` trên giá trị đã khóa. Không có bước kiểm tra "trước" không khóa, vì kết quả của nó có thể sai ngay sau khi đọc. |
| Khi nào tăng `reserved_quantity`? | Ngay sau khi kiểm tra đạt cho **tất cả** sách, vẫn trong lúc giữ lock (bước 6). Thiếu một sách → không sách nào được tăng. |
| Khi nào commit? | Sau bước 9, khi order, items, reservation, payment, history và cart đều đã ghi xong. Commit nhả toàn bộ lock; checkout đang chờ trên cùng dòng inventory lúc đó mới đọc được số đã cập nhật. |

Vì sao bước reserve đứng **sau** insert order dù spec liệt kê "Check inventory" trước "Create order": bút toán reservation cần `order.id` làm reference, và kiểm tra + reserve phải là một bước duy nhất dưới lock. Transaction bảo đảm thứ tự này không để lại trạng thái nửa vời; đồng thời lock inventory — tài nguyên bị tranh chấp nhiều nhất — được lấy **muộn nhất** nên thời gian giữ lock ngắn nhất.

### Thứ tự lock toàn hệ thống

| Luồng | Thứ tự |
|---|---|
| Sửa cart | carts |
| Checkout | carts → books (share) → inventory |
| Hủy đơn / đổi trạng thái | orders → inventory |
| Nhập kho / điều chỉnh | inventory |

Inventory luôn được khóa **cuối cùng** và theo `book_id` tăng dần → không có vòng chờ → không deadlock.

## Đơn hàng (`OrderService`)

State machine (`OrderStatus::nextStatuses()`):

```text
pending ──► confirmed ──► processing ──► shipped ──► delivered
   │            │              │
   └────────────┴──────────────┴──► cancelled
```

`changeStatus()`:
1. `SELECT orders ... FOR UPDATE` — khóa đơn.
2. Kiểm tra chuyển trạng thái **trên dòng đã khóa**; không hợp lệ (nhảy bước, đã shipped mà hủy, lặp lại) → 422.
3. Tác động tồn kho và payment: `cancelled` → `InventoryService::release()` + payment COD `failed`; `delivered` → `completeSale()` + payment COD `paid` (`orders.payment_status = paid`). Tất cả trong cùng transaction, dưới lock của đơn.
4. Cập nhật status, ghi `order_status_histories` (old, new, người thực hiện, ghi chú).

Customer hủy đơn qua `POST /orders/{id}/cancel`; admin đổi trạng thái (kể cả hủy) qua `PATCH /admin/orders/{id}/status`. Cả hai dùng chung `changeStatus()`.

**Không chạy hai lần**: request thứ hai (bấm lặp, retry, chạy song song) chờ ở lock của đơn, sau đó thấy trạng thái mới → chuyển trạng thái không còn hợp lệ → 422, không đụng tới tồn kho. Nếu lớp này bị bỏ qua, kiểm tra sổ cái và unique index của `InventoryService` vẫn chặn (đã kiểm chứng bằng cách tạm bỏ lock của đơn: stock vẫn chỉ bị trừ một lần).

## Test đồng thời

`tests/Feature/Concurrency/*` chạy mỗi request trong một process PHP riêng (`tests/Support/concurrency-worker.php`) với kết nối DB riêng. Helper `raceWorkers()` giữ lock trên dòng cần tranh chấp, chờ tới khi **mọi** worker đang đợi lock đó (theo `pg_stat_activity`), rồi mới nhả — các worker thật sự tranh chấp cùng lúc chứ không phụ thuộc may rủi.

| Test | Kết quả mong đợi |
|---|---|
| 2 customer checkout cuốn cuối cùng | 1 order; người thua nhận 422, order của họ bị rollback, cart giữ nguyên |
| 8 customer, tồn kho 3 | đúng 3 order |
| Cùng customer bấm checkout 2 lần | 1 order; lần hai nhận 422 "cart is empty" |
| 3 request hủy cùng đơn | 1 release |
| 3 request "delivered" cùng đơn | 1 sale |
| 12 reserve cho 5 cuốn; khóa ngược thứ tự | không oversell; không deadlock |
