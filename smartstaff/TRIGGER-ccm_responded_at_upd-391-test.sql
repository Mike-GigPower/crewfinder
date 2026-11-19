DELIMITER $$
CREATE OR REPLACE DEFINER=`smartst_testu`@`localhost` TRIGGER ccm_responded_at_upd
  BEFORE UPDATE ON call_crew_map FOR EACH ROW
BEGIN
	IF OLD.status = 0 AND NEW.responded_src = 'phone' THEN
		SET NEW.offered_at = NULL, NEW.responded_at = NULL;
	ELSEIF OLD.status IN (5,6,7,8,9,10,11,12) AND NEW.status = 0 THEN
		SET NEW.offered_at = NOW(), NEW.responded_at = NULL, NEW.responded_src = NULL;
	ELSEIF OLD.status = 0 AND NEW.status IN (5,6,7) AND NEW.responded_at IS NULL THEN
		SET NEW.responded_at = NOW();
	END IF;
END$$
DELIMITER ;

