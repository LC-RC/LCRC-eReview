-- Table for storing student annotations/notes on handouts
CREATE TABLE IF NOT EXISTS `handout_annotations` (
  `annotation_id` int(11) NOT NULL AUTO_INCREMENT,
  `handout_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `annotation_type` varchar(50) NOT NULL DEFAULT 'text',
  `page_number` int(11) NOT NULL DEFAULT 1,
  `x` decimal(10,2) DEFAULT NULL,
  `y` decimal(10,2) DEFAULT NULL,
  `width` decimal(10,2) DEFAULT NULL,
  `height` decimal(10,2) DEFAULT NULL,
  `content` mediumtext DEFAULT NULL,
  `color` varchar(20) DEFAULT '#000000',
  `font_size` int(11) DEFAULT 12,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`annotation_id`),
  KEY `handout_id` (`handout_id`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `handout_annotations_ibfk_1` FOREIGN KEY (`handout_id`) REFERENCES `lesson_handouts` (`handout_id`) ON DELETE CASCADE,
  CONSTRAINT `handout_annotations_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- If table already exists, alter content column to mediumtext
ALTER TABLE `handout_annotations` MODIFY `content` mediumtext DEFAULT NULL;
