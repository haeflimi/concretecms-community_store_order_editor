# community_store_order_editor

Full CRUD for [Community Store](https://github.com/concretecms-community-store/community_store) orders.
The store's own Orders page only lets an admin change the fulfilment status and the paid / refunded / cancelled
flags; this package adds **Dashboard › Store › Order Editor** (next to Orders, which stays untouched):

- **List**: all orders with search (order id, transaction reference, customer details), payment state filter
  and a customer filter; links into the store's order page and into the editor.
- **Create**: a new order for any customer (or no account), with date, payment method, shipping method,
  status, notes, contact details and totals. Items are added afterwards on the edit page.
- **Edit**:
  - header: customer, date, payment method (a configured one or free text), shipping method and
    instructions, transaction reference, notes, contact / billing / shipping text attributes;
  - items: add store products (with an optional price override) or free-text lines without a product,
    change name, SKU, quantity and price of every line, remove lines;
  - totals: shipping, tax, included tax, tax label and the order total, which can be set by hand or
    recalculated from items + shipping + tax;
  - payment state: mark paid (fires the store's payment events, like the store's own "Mark Paid"), reverse,
    mark refunded with reason, reverse, mark cancelled (fires the cancel event), reverse;
  - fulfilment status with comment and the status history.
- **Delete**: removes the order with its items, item options, attributes, discounts and status history.
- **Bulk-create** (like the credit manager's "Bulk Add Transactions"): the same order for every active user of a
  group or for all active users, with a label, date, payment method, status, notes and any number of product or
  free-text lines, optionally marked paid right away. Each run carries a batch id; the created orders are
  recorded in `csOrderEditorBulkOrders` (unique per batch and user), so a double submit or a re-run after an
  error creates every user's order once. The order list can be filtered by batch and shows the batch on each
  order; past batches are listed on the bulk page.

Every change is a token-protected POST. Nothing is sent to the customer by the editor itself; marking an order
paid runs the store's post-payment steps exactly as the store's Orders page does.

API: `CommunityStoreOrderEditor\Service\OrderEditor`.
