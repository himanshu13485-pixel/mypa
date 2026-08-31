<?php

namespace Tests\Unit;

use App\Support\TextCase;
use PHPUnit\Framework\TestCase;

/** House style for pasted-in names, addresses and identifiers. */
class TextCaseTest extends TestCase
{
    public function test_a_shouted_company_name_keeps_only_its_brand_acronym(): void
    {
        $this->assertSame(
            'CGPL Corpiness Global Pvt Ltd.',
            TextCase::company('CGPL CORPINESS GLOBAL PVT LTD.'),
        );
        $this->assertSame('Bhavya Steel Industries', TextCase::company('BHAVYA STEEL INDUSTRIES'));
        // A long first word is a word, not an acronym.
        $this->assertSame('Corpiness Global Pvt Ltd', TextCase::company('CORPINESS GLOBAL PVT LTD'));
    }

    public function test_capitals_the_writer_chose_are_left_alone(): void
    {
        // Mixed case means deliberate: short capitalised words survive.
        $this->assertSame('Corpcio Global LLC', TextCase::company('Corpcio Global LLC'));
        $this->assertSame('A & H Minerals', TextCase::company('A & H MINERALS'));
        $this->assertSame('3r Exim Pvt Ltd', TextCase::company('3r exim pvt ltd'));
    }

    public function test_person_names_are_title_cased_without_the_brand_rule(): void
    {
        $this->assertSame('Himanshu Sachdeva', TextCase::name('HIMANSHU SACHDEVA'));
        $this->assertSame('M.A. Kunwar', TextCase::name('M.A. Kunwar'));
        $this->assertSame("O'Brien-Smith", TextCase::name("o'brien-smith"));
        // Without the brand rule a short shouted first word is normalised.
        $this->assertSame('Raj Kumar', TextCase::name('RAJ KUMAR'));
    }

    public function test_plan_names_keep_roman_numerals_and_alphanumeric_codes(): void
    {
        // These are the old CRM's plan names, shouted from a spreadsheet.
        $this->assertSame('ARTIS - II + B2B Pages', TextCase::company('ARTIS - II + B2B PAGES'));
        $this->assertSame('ARTIS - Demo Paid', TextCase::company('ARTIS - DEMO PAID'));
        $this->assertSame('Online Plan Ltd', TextCase::company('ONLINE PLAN LTD'));
        $this->assertSame('Global Trade 24X7', TextCase::company('GLOBAL TRADE 24X7'));
    }

    public function test_emails_go_down_and_identifiers_go_up(): void
    {
        $this->assertSame('sales@cgplmail.com', TextCase::email('  Sales@CGPLmail.COM '));
        $this->assertSame('06ACNPY2693Q2ZD', TextCase::code(' 06acnpy2693q2zd '));
        $this->assertSame('HDFC0000001', TextCase::code('hdfc 0000 001'));
    }

    public function test_blanks_become_null_rather_than_empty_strings(): void
    {
        $this->assertNull(TextCase::company('   '));
        $this->assertNull(TextCase::email(''));
        $this->assertNull(TextCase::code('  '));
    }
}
