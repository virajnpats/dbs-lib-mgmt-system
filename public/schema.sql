CREATE TABLE MEMBERSHIP_PLAN (
  plan_id INT PRIMARY KEY,
  plan_name VARCHAR(50) NOT NULL UNIQUE,
  max_books INT NOT NULL CHECK (max_books > 0),
  annual_fee DECIMAL(8,2) NOT NULL CHECK (annual_fee >= 0),
  loan_days INT NOT NULL CHECK (loan_days > 0)
);
CREATE TABLE PUBLISHER (
  publisher_id INT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  website VARCHAR(200),
  address VARCHAR(200)
);
CREATE TABLE CATEGORY (
  category_id INT PRIMARY KEY,
  category_name VARCHAR(60) NOT NULL UNIQUE,
  description VARCHAR(255)
);
CREATE TABLE BRANCH (
  branch_id INT PRIMARY KEY,
  branch_name VARCHAR(100) NOT NULL,
  phone VARCHAR(20),
  address VARCHAR(200)
);
CREATE TABLE AUTHOR (
  author_id INT PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  nationality VARCHAR(50),
  birth_date DATE
);
CREATE TABLE BOOK (
  book_id INT PRIMARY KEY,
  isbn VARCHAR(20) UNIQUE,
  title VARCHAR(200) NOT NULL,
  language VARCHAR(40),
  publish_year INT,
  edition VARCHAR(30),
  publisher_id INT,
  category_id INT,
  FOREIGN KEY (publisher_id) REFERENCES PUBLISHER(publisher_id),
  FOREIGN KEY (category_id) REFERENCES CATEGORY(category_id),
  CHECK (publish_year IS NULL OR publish_year BETWEEN 1000 AND 2100)
);
CREATE TABLE MEMBER (
  member_id INT PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(100) UNIQUE,
  phone VARCHAR(20),
  address VARCHAR(200),
  status VARCHAR(20) NOT NULL DEFAULT 'Active',
  date_of_birth DATE,
  join_date DATE NOT NULL,
  plan_id INT NOT NULL,
  FOREIGN KEY (plan_id) REFERENCES MEMBERSHIP_PLAN(plan_id),
  CHECK (status IN ('Active', 'Inactive'))
);
CREATE TABLE STAFF (
  staff_id INT PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  role VARCHAR(50),
  email VARCHAR(100) UNIQUE,
  hire_date DATE,
  branch_id INT NOT NULL,
  FOREIGN KEY (branch_id) REFERENCES BRANCH(branch_id)
);
CREATE TABLE BOOK_AUTHOR (
  book_id INT NOT NULL,
  author_id INT NOT NULL,
  PRIMARY KEY (book_id, author_id),
  FOREIGN KEY (book_id) REFERENCES BOOK(book_id),
  FOREIGN KEY (author_id) REFERENCES AUTHOR(author_id)
);
CREATE TABLE BOOK_COPY (
  book_id INT NOT NULL,
  copy_no INT NOT NULL,
  shelf_location VARCHAR(50),
  `condition` VARCHAR(30),
  status VARCHAR(20) NOT NULL DEFAULT 'Available',
  price DECIMAL(8,2),
  branch_id INT NOT NULL,
  PRIMARY KEY (book_id, copy_no),
  FOREIGN KEY (book_id) REFERENCES BOOK(book_id),
  FOREIGN KEY (branch_id) REFERENCES BRANCH(branch_id),
  CHECK (price IS NULL OR price >= 0),
  CHECK (`condition` IS NULL OR `condition` IN ('New','Good','Fair','Damaged')),
  CHECK (status IN ('Available','Borrowed','Reserved','Maintenance'))
);
CREATE TABLE RESERVATION (
  reservation_id INT PRIMARY KEY,
  reserved_on DATE NOT NULL,
  expires_on DATE,
  status VARCHAR(20) NOT NULL DEFAULT 'Pending',
  member_id INT NOT NULL,
  book_id INT NOT NULL,
  FOREIGN KEY (member_id) REFERENCES MEMBER(member_id),
  FOREIGN KEY (book_id) REFERENCES BOOK(book_id),
  CHECK (status IN ('Pending','Fulfilled','Cancelled','Expired')),
  CHECK (expires_on IS NULL OR expires_on >= reserved_on)
);
CREATE TABLE LOAN (
  loan_id INT PRIMARY KEY,
  issue_date DATE NOT NULL,
  due_date DATE NOT NULL,
  return_date DATE,
  status VARCHAR(20) NOT NULL DEFAULT 'Borrowed',
  member_id INT NOT NULL,
  book_id INT NOT NULL,
  copy_no INT NOT NULL,
  staff_id INT NOT NULL,
  FOREIGN KEY (member_id) REFERENCES MEMBER(member_id),
  FOREIGN KEY (book_id, copy_no) REFERENCES BOOK_COPY(book_id, copy_no),
  FOREIGN KEY (staff_id) REFERENCES STAFF(staff_id),
  CHECK (due_date >= issue_date),
  CHECK (return_date IS NULL OR return_date >= issue_date),
  CHECK (status IN ('Borrowed','Returned','Overdue'))
);
CREATE TABLE FINE (
  fine_no INT PRIMARY KEY,
  amount DECIMAL(8,2) NOT NULL CHECK (amount >= 0),
  reason VARCHAR(200),
  status VARCHAR(20) NOT NULL DEFAULT 'Unpaid',
  fine_date DATE NOT NULL,
  loan_id INT NOT NULL UNIQUE,
  FOREIGN KEY (loan_id) REFERENCES LOAN(loan_id),
  CHECK (status IN ('Unpaid','Partially Paid','Paid'))
);
CREATE TABLE PAYMENT (
  payment_no INT PRIMARY KEY,
  amount_paid DECIMAL(8,2) NOT NULL CHECK (amount_paid > 0),
  payment_date DATE NOT NULL,
  method VARCHAR(30) NOT NULL,
  fine_no INT NOT NULL,
  FOREIGN KEY (fine_no) REFERENCES FINE(fine_no),
  CHECK (method IN ('Cash','Card','UPI','Online'))
);