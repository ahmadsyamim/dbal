<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Platforms;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\ColumnLengthRequired;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\DBAL\Types\Type;

use function sprintf;
use function strtoupper;
use function uniqid;

/**
 * @extends AbstractPlatformTestCase<OraclePlatform>
 */
class OraclePlatformTest extends AbstractPlatformTestCase
{
    public function createPlatform(): AbstractPlatform
    {
        return new OraclePlatform();
    }

    public function getGenerateTableSql(): string
    {
        return 'CREATE TABLE test (id NUMBER(10) NOT NULL, test VARCHAR2(255) DEFAULT NULL NULL, PRIMARY KEY(id))';
    }

    /**
     * {@inheritDoc}
     */
    public function getGenerateTableWithMultiColumnUniqueIndexSql(): array
    {
        return [
            'CREATE TABLE test (foo VARCHAR2(255) DEFAULT NULL NULL, bar VARCHAR2(255) DEFAULT NULL NULL)',
            'CREATE UNIQUE INDEX UNIQ_D87F7E0C8C73652176FF8CAA ON test (foo, bar)',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getGenerateAlterTableSql(): array
    {
        return [
            'ALTER TABLE mytable ADD (quota NUMBER(10) DEFAULT NULL NULL)',
            "ALTER TABLE mytable MODIFY (baz VARCHAR2(255) DEFAULT 'def' NOT NULL, "
                . 'bloo NUMBER(1) DEFAULT 0 NOT NULL)',
            'ALTER TABLE mytable DROP (foo)',
            'ALTER TABLE mytable RENAME TO userlist',
        ];
    }

    public function testRLike(): void
    {
        $this->expectException(Exception::class);

        self::assertEquals('RLIKE', $this->platform->getRegexpExpression());
    }

    public function testGeneratesSqlSnippets(): void
    {
        self::assertEquals(
            'column1 || column2 || column3',
            $this->platform->getConcatExpression('column1', 'column2', 'column3')
        );
    }

    public function testGeneratesTransactionsCommands(): void
    {
        self::assertEquals(
            'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED',
            $this->platform->getSetTransactionIsolationSQL(TransactionIsolationLevel::READ_UNCOMMITTED)
        );
        self::assertEquals(
            'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
            $this->platform->getSetTransactionIsolationSQL(TransactionIsolationLevel::READ_COMMITTED)
        );
        self::assertEquals(
            'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE',
            $this->platform->getSetTransactionIsolationSQL(TransactionIsolationLevel::REPEATABLE_READ)
        );
        self::assertEquals(
            'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE',
            $this->platform->getSetTransactionIsolationSQL(TransactionIsolationLevel::SERIALIZABLE)
        );
    }

    public function testCreateDatabaseSQL(): void
    {
        self::assertEquals('CREATE USER foobar', $this->platform->getCreateDatabaseSQL('foobar'));
    }

    public function testDropDatabaseSQL(): void
    {
        self::assertEquals('DROP USER foobar CASCADE', $this->platform->getDropDatabaseSQL('foobar'));
    }

    public function testDropTable(): void
    {
        self::assertEquals('DROP TABLE foobar', $this->platform->getDropTableSQL('foobar'));
    }

    public function testGeneratesTypeDeclarationForIntegers(): void
    {
        self::assertEquals(
            'NUMBER(10)',
            $this->platform->getIntegerTypeDeclarationSQL([])
        );
        self::assertEquals(
            'NUMBER(10)',
            $this->platform->getIntegerTypeDeclarationSQL(['autoincrement' => true])
        );
        self::assertEquals(
            'NUMBER(10)',
            $this->platform->getIntegerTypeDeclarationSQL(
                ['autoincrement' => true, 'primary' => true]
            )
        );
    }

    public function testSupportsIdentityColumns(): void
    {
        self::assertFalse($this->platform->supportsIdentityColumns());
    }

    public function testSupportsSavePoints(): void
    {
        self::assertTrue($this->platform->supportsSavepoints());
    }

    protected function supportsCommentOnStatement(): bool
    {
        return true;
    }

    public function getGenerateIndexSql(): string
    {
        return 'CREATE INDEX my_idx ON mytable (user_name, last_login)';
    }

    public function getGenerateUniqueIndexSql(): string
    {
        return 'CREATE UNIQUE INDEX index_name ON test (test, test2)';
    }

    protected function getGenerateForeignKeySql(): string
    {
        return 'ALTER TABLE test ADD FOREIGN KEY (fk_name_id) REFERENCES other_table (id)';
    }

    /**
     * @param mixed[] $options
     *
     * @dataProvider getGeneratesAdvancedForeignKeyOptionsSQLData
     */
    public function testGeneratesAdvancedForeignKeyOptionsSQL(array $options, string $expectedSql): void
    {
        $foreignKey = new ForeignKeyConstraint(['foo'], 'foreign_table', ['bar'], '', $options);

        self::assertSame($expectedSql, $this->platform->getAdvancedForeignKeyOptionsSQL($foreignKey));
    }

    /**
     * @return mixed[][]
     */
    public static function getGeneratesAdvancedForeignKeyOptionsSQLData(): iterable
    {
        return [
            [[], ''],
            [['onUpdate' => 'CASCADE'], ''],
            [['onDelete' => 'CASCADE'], ' ON DELETE CASCADE'],
            [['onDelete' => 'NO ACTION'], ''],
            [['onDelete' => 'RESTRICT'], ''],
            [['onUpdate' => 'SET NULL', 'onDelete' => 'SET NULL'], ' ON DELETE SET NULL'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function getReturnsForeignKeyReferentialActionSQL(): iterable
    {
        return [
            ['CASCADE', 'CASCADE'],
            ['SET NULL', 'SET NULL'],
            ['NO ACTION', ''],
            ['RESTRICT', ''],
            ['CaScAdE', 'CASCADE'],
        ];
    }

    public function testGenerateTableWithAutoincrement(): void
    {
        $columnName = strtoupper('id' . uniqid());
        $tableName  = strtoupper('table' . uniqid());
        $table      = new Table($tableName);

        $column = $table->addColumn($columnName, 'integer');
        $column->setAutoincrement(true);

        self::assertSame([
            sprintf('CREATE TABLE %s (%s NUMBER(10) NOT NULL)', $tableName, $columnName),
            sprintf(
                <<<'SQL'
DECLARE
  constraints_Count NUMBER;
BEGIN
  SELECT COUNT(CONSTRAINT_NAME) INTO constraints_Count
    FROM USER_CONSTRAINTS
   WHERE TABLE_NAME = '%s'
     AND CONSTRAINT_TYPE = 'P';
  IF constraints_Count = 0 OR constraints_Count = '' THEN
    EXECUTE IMMEDIATE 'ALTER TABLE %s ADD CONSTRAINT %s_AI_PK PRIMARY KEY (%s)';
  END IF;
END;
SQL
                ,
                $tableName,
                $tableName,
                $tableName,
                $columnName
            ),
            sprintf('CREATE SEQUENCE %s_SEQ START WITH 1 MINVALUE 1 INCREMENT BY 1', $tableName),
            sprintf(
                <<<SQL
CREATE TRIGGER %s_AI_PK
   BEFORE INSERT
   ON %s
   FOR EACH ROW
DECLARE
   last_Sequence NUMBER;
   last_InsertID NUMBER;
BEGIN
   IF (:NEW.%s IS NULL OR :NEW.%s = 0) THEN
      SELECT %s_SEQ.NEXTVAL INTO :NEW.%s FROM DUAL;
   ELSE
      SELECT NVL(Last_Number, 0) INTO last_Sequence
        FROM User_Sequences
       WHERE Sequence_Name = '%s_SEQ';
      SELECT :NEW.%s INTO last_InsertID FROM DUAL;
      WHILE (last_InsertID > last_Sequence) LOOP
         SELECT %s_SEQ.NEXTVAL INTO last_Sequence FROM DUAL;
      END LOOP;
      SELECT %s_SEQ.NEXTVAL INTO last_Sequence FROM DUAL;
   END IF;
END;
SQL
                ,
                $tableName,
                $tableName,
                $columnName,
                $columnName,
                $tableName,
                $columnName,
                $tableName,
                $columnName,
                $tableName,
                $tableName
            ),
        ], $this->platform->getCreateTableSQL($table));
    }

    /**
     * {@inheritDoc}
     */
    public function getCreateTableColumnCommentsSQL(): array
    {
        return [
            'CREATE TABLE test (id NUMBER(10) NOT NULL, PRIMARY KEY(id))',
            "COMMENT ON COLUMN test.id IS 'This is a comment'",
        ];
    }

    public function getBitAndComparisonExpressionSql(string $value1, string $value2): string
    {
        return 'BITAND(' . $value1 . ', ' . $value2 . ')';
    }

    public function getBitOrComparisonExpressionSql(string $value1, string $value2): string
    {
        return '(' . $value1 . '-' .
        $this->getBitAndComparisonExpressionSql($value1, $value2)
        . '+' . $value2 . ')';
    }

    /**
     * {@inheritDoc}
     */
    protected function getQuotedColumnInPrimaryKeySQL(): array
    {
        return ['CREATE TABLE "quoted" ("create" VARCHAR2(255) NOT NULL, PRIMARY KEY("create"))'];
    }

    /**
     * {@inheritDoc}
     */
    protected function getQuotedColumnInIndexSQL(): array
    {
        return [
            'CREATE TABLE "quoted" ("create" VARCHAR2(255) NOT NULL)',
            'CREATE INDEX IDX_22660D028FD6E0FB ON "quoted" ("create")',
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function getQuotedNameInIndexSQL(): array
    {
        return [
            'CREATE TABLE test (column1 VARCHAR2(255) NOT NULL)',
            'CREATE INDEX "key" ON test (column1)',
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function getQuotedColumnInForeignKeySQL(): array
    {
        return [
            'CREATE TABLE "quoted" ("create" VARCHAR2(255) NOT NULL, foo VARCHAR2(255) NOT NULL, '
                . '"bar" VARCHAR2(255) NOT NULL)',
            'ALTER TABLE "quoted" ADD CONSTRAINT FK_WITH_RESERVED_KEYWORD FOREIGN KEY ("create", foo, "bar")'
                . ' REFERENCES foreign ("create", bar, "foo-bar")',
            'ALTER TABLE "quoted" ADD CONSTRAINT FK_WITH_NON_RESERVED_KEYWORD FOREIGN KEY ("create", foo, "bar")'
                . ' REFERENCES foo ("create", bar, "foo-bar")',
            'ALTER TABLE "quoted" ADD CONSTRAINT FK_WITH_INTENDED_QUOTATION FOREIGN KEY ("create", foo, "bar")'
                . ' REFERENCES "foo-bar" ("create", bar, "foo-bar")',
            'CREATE INDEX IDX_22660D028FD6E0FB8C736521D79164E3 ON "quoted" ("create", foo, "bar")',
        ];
    }

    public function testInitializesDoctrineTypeMappings(): void
    {
        self::assertTrue($this->platform->hasDoctrineTypeMappingFor('long raw'));
        self::assertSame('blob', $this->platform->getDoctrineTypeMapping('long raw'));

        self::assertTrue($this->platform->hasDoctrineTypeMappingFor('raw'));
        self::assertSame('binary', $this->platform->getDoctrineTypeMapping('raw'));

        self::assertTrue($this->platform->hasDoctrineTypeMappingFor('date'));
        self::assertSame('date', $this->platform->getDoctrineTypeMapping('date'));
    }

    public function testGetVariableLengthStringTypeDeclarationSQLNoLength(): void
    {
        $this->expectException(ColumnLengthRequired::class);

        parent::testGetVariableLengthStringTypeDeclarationSQLNoLength();
    }

    protected function getExpectedVariableLengthStringTypeDeclarationSQLWithLength(): string
    {
        return 'VARCHAR2(16)';
    }

    public function testGetFixedLengthBinaryTypeDeclarationSQLNoLength(): void
    {
        $this->expectException(ColumnLengthRequired::class);

        parent::testGetFixedLengthBinaryTypeDeclarationSQLNoLength();
    }

    public function getExpectedFixedLengthBinaryTypeDeclarationSQLWithLength(): string
    {
        return 'RAW(16)';
    }

    public function testGetVariableLengthBinaryTypeDeclarationSQLNoLength(): void
    {
        $this->expectException(ColumnLengthRequired::class);

        parent::testGetVariableLengthBinaryTypeDeclarationSQLNoLength();
    }

    public function getExpectedVariableLengthBinaryTypeDeclarationSQLWithLength(): string
    {
        return 'RAW(16)';
    }

    public function testDoesNotPropagateUnnecessaryTableAlterationOnBinaryType(): void
    {
        $table1 = new Table('mytable');
        $table1->addColumn('column_varbinary', 'binary', ['length' => 32]);
        $table1->addColumn('column_binary', 'binary', [
            'fixed' => true,
            'length' => 32,
        ]);

        $table2 = new Table('mytable');
        $table2->addColumn('column_varbinary', 'binary', [
            'fixed' => true,
            'length' => 32,
        ]);
        $table2->addColumn('column_binary', 'binary', ['length' => 32]);

        self::assertNull(
            $this->createComparator()
                ->diffTable($table1, $table2)
        );
    }

    /**
     * @dataProvider dataCreateSequenceWithCache
     */
    public function testCreateSequenceWithCache(int $cacheSize, string $expectedSql): void
    {
        $sequence = new Sequence('foo', 1, 1, $cacheSize);
        self::assertStringContainsString($expectedSql, $this->platform->getCreateSequenceSQL($sequence));
    }

    /**
     * @return mixed[][]
     */
    public static function dataCreateSequenceWithCache(): iterable
    {
        return [
            [1, 'NOCACHE'],
            [0, 'NOCACHE'],
            [3, 'CACHE 3'],
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function getAlterTableRenameIndexSQL(): array
    {
        return ['ALTER INDEX idx_foo RENAME TO idx_bar'];
    }

    /**
     * {@inheritDoc}
     */
    protected function getQuotedAlterTableRenameIndexSQL(): array
    {
        return [
            'ALTER INDEX "create" RENAME TO "select"',
            'ALTER INDEX "foo" RENAME TO "bar"',
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getQuotedAlterTableRenameColumnSQL(): array
    {
        return [
            'ALTER TABLE mytable RENAME COLUMN unquoted1 TO unquoted',
            'ALTER TABLE mytable RENAME COLUMN unquoted2 TO "where"',
            'ALTER TABLE mytable RENAME COLUMN unquoted3 TO "foo"',
            'ALTER TABLE mytable RENAME COLUMN "create" TO reserved_keyword',
            'ALTER TABLE mytable RENAME COLUMN "table" TO "from"',
            'ALTER TABLE mytable RENAME COLUMN "select" TO "bar"',
            'ALTER TABLE mytable RENAME COLUMN quoted1 TO quoted',
            'ALTER TABLE mytable RENAME COLUMN quoted2 TO "and"',
            'ALTER TABLE mytable RENAME COLUMN quoted3 TO "baz"',
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getQuotedAlterTableChangeColumnLengthSQL(): array
    {
        self::markTestIncomplete('Not implemented yet');
    }

    /**
     * {@inheritDoc}
     */
    protected function getAlterTableRenameIndexInSchemaSQL(): array
    {
        return ['ALTER INDEX myschema.idx_foo RENAME TO idx_bar'];
    }

    /**
     * {@inheritDoc}
     */
    protected function getQuotedAlterTableRenameIndexInSchemaSQL(): array
    {
        return [
            'ALTER INDEX "schema"."create" RENAME TO "select"',
            'ALTER INDEX "schema"."foo" RENAME TO "bar"',
        ];
    }

    public function testReturnsGuidTypeDeclarationSQL(): void
    {
        self::assertSame('CHAR(36)', $this->platform->getGuidTypeDeclarationSQL([]));
    }

    /**
     * {@inheritdoc}
     */
    public function getAlterTableRenameColumnSQL(): array
    {
        return ['ALTER TABLE foo RENAME COLUMN bar TO baz'];
    }

    /**
     * @param string[] $expectedSql
     *
     * @dataProvider getReturnsDropAutoincrementSQL
     */
    public function testReturnsDropAutoincrementSQL(string $table, array $expectedSql): void
    {
        self::assertSame($expectedSql, $this->platform->getDropAutoincrementSql($table));
    }

    /**
     * @return mixed[][]
     */
    public static function getReturnsDropAutoincrementSQL(): iterable
    {
        return [
            [
                'myTable',
                [
                    'DROP TRIGGER MYTABLE_AI_PK',
                    'DROP SEQUENCE MYTABLE_SEQ',
                    'ALTER TABLE MYTABLE DROP CONSTRAINT MYTABLE_AI_PK',
                ],
            ],
            [
                '"myTable"',
                [
                    'DROP TRIGGER "myTable_AI_PK"',
                    'DROP SEQUENCE "myTable_SEQ"',
                    'ALTER TABLE "myTable" DROP CONSTRAINT "myTable_AI_PK"',
                ],
            ],
            [
                'table',
                [
                    'DROP TRIGGER TABLE_AI_PK',
                    'DROP SEQUENCE TABLE_SEQ',
                    'ALTER TABLE "TABLE" DROP CONSTRAINT TABLE_AI_PK',
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getQuotesTableIdentifiersInAlterTableSQL(): array
    {
        return [
            'ALTER TABLE "foo" DROP CONSTRAINT fk1',
            'ALTER TABLE "foo" DROP CONSTRAINT fk2',
            'ALTER TABLE "foo" ADD (bloo NUMBER(10) NOT NULL)',
            'ALTER TABLE "foo" MODIFY (bar NUMBER(10) DEFAULT NULL NULL)',
            'ALTER TABLE "foo" RENAME COLUMN id TO war',
            'ALTER TABLE "foo" DROP (baz)',
            'ALTER TABLE "foo" RENAME TO "table"',
            'ALTER TABLE "table" ADD CONSTRAINT fk_add FOREIGN KEY (fk3) REFERENCES fk_table (id)',
            'ALTER TABLE "table" ADD CONSTRAINT fk2 FOREIGN KEY (fk2) REFERENCES fk_table2 (id)',
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getCommentOnColumnSQL(): array
    {
        return [
            'COMMENT ON COLUMN foo.bar IS \'comment\'',
            'COMMENT ON COLUMN "Foo"."BAR" IS \'comment\'',
            'COMMENT ON COLUMN "select"."from" IS \'comment\'',
        ];
    }

    public function testAltersTableColumnCommentWithExplicitlyQuotedIdentifiers(): void
    {
        $table1 = new Table('"foo"', [new Column('"bar"', Type::getType('integer'))]);
        $table2 = new Table('"foo"', [new Column('"bar"', Type::getType('integer'), ['comment' => 'baz'])]);

        $tableDiff = $this->createComparator()
            ->diffTable($table1, $table2);

        self::assertNotNull($tableDiff);
        self::assertSame(
            ['COMMENT ON COLUMN "foo"."bar" IS \'baz\''],
            $this->platform->getAlterTableSQL($tableDiff)
        );
    }

    public function testQuotedTableNames(): void
    {
        $table = new Table('"test"');
        $table->addColumn('"id"', 'integer', ['autoincrement' => true]);

        // assert tabel
        self::assertTrue($table->isQuoted());
        self::assertEquals('test', $table->getName());
        self::assertEquals('"test"', $table->getQuotedName($this->platform));

        $sql = $this->platform->getCreateTableSQL($table);
        self::assertEquals('CREATE TABLE "test" ("id" NUMBER(10) NOT NULL)', $sql[0]);
        self::assertEquals('CREATE SEQUENCE "test_SEQ" START WITH 1 MINVALUE 1 INCREMENT BY 1', $sql[2]);
        $createTriggerStatement = <<<EOD
CREATE TRIGGER "test_AI_PK"
   BEFORE INSERT
   ON "test"
   FOR EACH ROW
DECLARE
   last_Sequence NUMBER;
   last_InsertID NUMBER;
BEGIN
   IF (:NEW."id" IS NULL OR :NEW."id" = 0) THEN
      SELECT "test_SEQ".NEXTVAL INTO :NEW."id" FROM DUAL;
   ELSE
      SELECT NVL(Last_Number, 0) INTO last_Sequence
        FROM User_Sequences
       WHERE Sequence_Name = 'test_SEQ';
      SELECT :NEW."id" INTO last_InsertID FROM DUAL;
      WHILE (last_InsertID > last_Sequence) LOOP
         SELECT "test_SEQ".NEXTVAL INTO last_Sequence FROM DUAL;
      END LOOP;
      SELECT "test_SEQ".NEXTVAL INTO last_Sequence FROM DUAL;
   END IF;
END;
EOD;

        self::assertEquals($createTriggerStatement, $sql[3]);
    }

    protected function getQuotesReservedKeywordInUniqueConstraintDeclarationSQL(): string
    {
        return 'CONSTRAINT "select" UNIQUE (foo)';
    }

    protected function getQuotesReservedKeywordInIndexDeclarationSQL(): string
    {
        return 'INDEX "select" (foo)';
    }

    protected function getQuotesReservedKeywordInTruncateTableSQL(): string
    {
        return 'TRUNCATE TABLE "select"';
    }

    /**
     * {@inheritdoc}
     */
    protected function getAlterStringToFixedStringSQL(): array
    {
        return ['ALTER TABLE mytable MODIFY (name CHAR(2) DEFAULT NULL)'];
    }

    /**
     * {@inheritdoc}
     */
    protected function getGeneratesAlterTableRenameIndexUsedByForeignKeySQL(): array
    {
        return ['ALTER INDEX idx_foo RENAME TO idx_foo_renamed'];
    }

    public function testQuotesDatabaseNameInListSequencesSQL(): void
    {
        self::assertStringContainsStringIgnoringCase(
            "'Foo''Bar\\'",
            $this->platform->getListSequencesSQL("Foo'Bar\\")
        );
    }

    /**
     * @return array<int, array{string, array<string, mixed>}>
     */
    public function asciiStringSqlDeclarationDataProvider(): array
    {
        return [
            ['VARCHAR2(12)', ['length' => 12]],
            ['CHAR(12)', ['length' => 12, 'fixed' => true]],
        ];
    }
}
