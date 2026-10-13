-- Remove duplicate cities from the checkout dropdown.
--
-- These older, broader zones overlap newer, more specific ones with the SAME
-- cost, so customers saw both (e.g. "Red Sea" and "Red Sea (Hurghada)").
-- They are only switched off, not deleted: past orders store the zone name,
-- not its id, so nothing else changes. Same as unticking "Active" for each
-- one in Admin -> Shipping.
--
--   31  Cairo & Giza  -> covered by Cairo (33), Giza (34)          95 EGP
--   25  Red Sea       -> Red Sea (Hurghada) (61)                   130 EGP
--   27  North Sinai   -> North Sinai (Arish) (67)                  140 EGP
--   28  South Sinai   -> South Sinai (Sharm El Sheikh & Dahab) (68) 140 EGP
--   29  New Valley    -> New Valley (Kharga) (69)                  140 EGP

UPDATE shipping_zones SET is_active = 0 WHERE id IN (25, 27, 28, 29, 31);
