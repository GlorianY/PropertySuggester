import unittest
from testtools import TestCase
from testtools.matchers import *
import TableGenerator
from claim import Claim

test_data1 = [('Q15', [Claim(31, 'wikibase-entityid', 'Q5107'),
                       Claim(373, 'string', 'Africa')]),
              ('Q16', [Claim(31, 'wikibase-entityid', 'Q384')])]

test_data2 = [('Q15', [Claim(31, 'wikibase-entityid', 'Q5107'),
                       Claim(373, 'string', 'Africa'),
                       Claim(373, 'string', 'Europe')])]


class TableGeneratorTest(TestCase):
    def setUp(self):
        TestCase.setUp(self)

    def testTableGenerator(self):
        table = TableGenerator.compute_table(test_data1)
        self.assertThat(table, ContainsAll((31, 373)))

        self.assertThat(table[31]['appearances'], Equals(2))
        self.assertThat(table[31]['type'], Equals('wikibase-entityid'))
        self.assertThat(table[31][31], Equals(0))
        self.assertThat(table[31][373], Equals(1))

        self.assertThat(table[373]['appearances'], Equals(1))
        self.assertThat(table[373]['type'], Equals('string'))
        self.assertThat(table[373][373], Equals(0))
        self.assertThat(table[373][31], Equals(1))

    def testTableWithMultipleOccurance(self):
        table = TableGenerator.compute_table(test_data2)

        self.assertThat(table[31]['appearances'], Equals(1))
        self.assertThat(table[31]['type'], Equals('wikibase-entityid'))
        self.assertThat(table[31][31], Equals(0))
        self.assertThat(table[31][373], Equals(2))

        self.assertThat(table[373]['appearances'], Equals(2))
        self.assertThat(table[373]['type'], Equals('string'))
        self.assertThat(table[373][373], Equals(0))
        self.assertThat(table[373][31], Equals(2))


if __name__ == '__main__':
    unittest.main()