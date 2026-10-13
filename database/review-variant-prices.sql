-- Variant price overrides vs product prices
--
-- A size's price_override is what the cart charges. Until now product cards
-- ignored it, so 42 products showed one price on the card and charged another
-- (e.g. Skyward Ribbon Ring: card 220, cart 449.99). The cards now show the
-- override, so they match the cart. But many overrides look like old prices
-- left over from before the current pricing, so review them.
--
-- STEP 1 - PREVIEW (read-only): every product whose sizes carry their own price.
SELECT p.id, p.name, p.price, p.sale_price,
       GROUP_CONCAT(CONCAT(v.size, '=', v.price_override) ORDER BY v.sort_order SEPARATOR '  ') AS size_prices
FROM products p
JOIN product_variants v ON v.product_id = p.id AND v.is_active = 1
WHERE v.price_override > 0
GROUP BY p.id, p.name, p.price, p.sale_price
ORDER BY p.name;

-- STEP 2 - ONLY IF the overrides are stale: clear them so every size uses the
-- product's own price (and its sale). Back up first (phpMyAdmin -> Export).
-- To clear only some products, add  AND product_id IN (...)  to the WHERE.
--
-- UPDATE product_variants SET price_override = NULL WHERE price_override > 0;
