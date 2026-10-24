<?php

namespace Database\Seeders\Demo;

/**
 * Names and places for the demo schools. Hand-picked rather than Faker
 * (a dev-only package), so the demo can also be built on a server.
 */
final class DemoData
{
    public const BOYS = [
        'Ali', 'Ahmed', 'Hassan', 'Hussain', 'Usman', 'Umar', 'Bilal', 'Hamza', 'Saad', 'Talha',
        'Ibrahim', 'Abdullah', 'Rayyan', 'Ayaan', 'Arham', 'Mustafa', 'Haris', 'Danish', 'Faizan', 'Shayan',
        'Zayan', 'Rohan', 'Arsalan', 'Daniyal', 'Fahad', 'Hammad', 'Junaid', 'Kashan', 'Moiz', 'Muneeb',
        'Nabeel', 'Omer', 'Qasim', 'Raafay', 'Saim', 'Sameer', 'Shahzaib', 'Taimoor', 'Waleed', 'Yahya',
        'Zaid', 'Azan', 'Huzaifa', 'Abdul Hadi', 'Muhammad Ali', 'Ammar', 'Anas', 'Awais', 'Ehsan', 'Faris',
        'Ghazi', 'Hadi', 'Imad', 'Jibran', 'Khizar', 'Musa', 'Noman', 'Rafay', 'Sufyan', 'Wahaj',
    ];

    public const GIRLS = [
        'Fatima', 'Zainab', 'Maryam', 'Aisha', 'Hira', 'Iqra', 'Laiba', 'Areeba', 'Anaya', 'Eshal',
        'Hoorain', 'Inaya', 'Javeria', 'Kinza', 'Mahnoor', 'Minahil', 'Noor', 'Rameen', 'Sana', 'Sidra',
        'Alishba', 'Amna', 'Dua', 'Emaan', 'Haniya', 'Hafsa', 'Khadija', 'Maham', 'Manahil', 'Mishal',
        'Momina', 'Nimra', 'Rida', 'Rumaisa', 'Sara', 'Shanzay', 'Sumaiya', 'Tooba', 'Umaima', 'Wajiha',
        'Zoya', 'Abeeha', 'Aleena', 'Anabia', 'Arfa', 'Barira', 'Duaa', 'Fiza', 'Haleema', 'Irha',
        'Jannat', 'Mahrukh', 'Mehak', 'Natasha', 'Rania', 'Ruqayya', 'Sabeen', 'Sehar', 'Urwa', 'Zunaira',
    ];

    public const SURNAMES = [
        'Khan', 'Ahmed', 'Malik', 'Butt', 'Chaudhry', 'Sheikh', 'Qureshi', 'Siddiqui', 'Raza', 'Hussain',
        'Akhtar', 'Javed', 'Mirza', 'Rana', 'Bhatti', 'Abbasi', 'Hashmi', 'Gill', 'Cheema', 'Awan',
        'Rizvi', 'Shah', 'Zaidi', 'Baig', 'Aziz', 'Farooq', 'Nawaz', 'Riaz', 'Tariq', 'Anwar',
        'Saleem', 'Rafiq', 'Sadiq', 'Jamil', 'Haider', 'Kazmi', 'Lodhi', 'Niazi', 'Warraich', 'Bajwa',
    ];

    public const FATHERS = [
        'Imtiaz', 'Kamran', 'Shahid', 'Nadeem', 'Asif', 'Tariq', 'Khalid', 'Waseem', 'Naveed', 'Zahid',
        'Rizwan', 'Faisal', 'Adnan', 'Irfan', 'Sajjad', 'Atif', 'Babar', 'Farhan', 'Haroon', 'Jawad',
        'Kashif', 'Majid', 'Mohsin', 'Nauman', 'Qaiser', 'Rashid', 'Salman', 'Shoaib', 'Tahir', 'Umair',
        'Yasir', 'Zeeshan', 'Aamir', 'Bilal', 'Ejaz', 'Fawad', 'Ghulam Abbas', 'Junaid', 'Mansoor', 'Raheel',
        'Sarfraz', 'Shakeel', 'Taimur', 'Wajid', 'Zafar', 'Akmal', 'Danish', 'Hammad', 'Javed', 'Nasir',
    ];

    public const MOTHERS = [
        'Saima', 'Ayesha', 'Fatima', 'Rabia', 'Sadia', 'Nadia', 'Amna', 'Asma', 'Bushra', 'Farah',
        'Huma', 'Kiran', 'Lubna', 'Mehwish', 'Nazia', 'Rubina', 'Samina', 'Shazia', 'Sobia', 'Tahira',
        'Uzma', 'Yasmin', 'Zara', 'Aneela', 'Erum', 'Ghazala', 'Hina', 'Iram', 'Madiha', 'Naila',
    ];

    public const OCCUPATIONS = [
        'Businessman', 'Software Engineer', 'Doctor', 'Banker', 'Civil Engineer', 'Government Officer',
        'Shopkeeper', 'Lawyer', 'Accountant', 'Textile Merchant', 'Pharmacist', 'Army Officer',
        'University Lecturer', 'Property Dealer', 'Electrical Engineer', 'Journalist', 'Architect',
        'Chartered Accountant', 'Restaurant Owner', 'Sales Manager',
    ];

    /**
     * Lahore neighbourhoods families live in, with the bus stop that serves
     * each ([route key, stop], or null where no school route goes).
     *
     * @var array<string, array{0: string, 1: string}|null>
     */
    public const AREAS = [
        'Model Town' => ['R1', 'Model Town Link Road'],
        'Faisal Town' => ['R1', 'Faisal Town'],
        'Iqbal Town' => ['R1', 'Akbar Chowk'],
        'Township' => ['R1', 'Township'],
        'Johar Town' => ['R2', 'Expo Centre'],
        'Wapda Town' => ['R2', 'Wapda Town'],
        'Canal Bank' => ['R2', 'Shaukat Khanum'],
        'Gulberg III' => ['R3', 'Liberty Market'],
        'Gulberg II' => ['R3', 'Main Boulevard Gulberg'],
        'Garden Town' => ['R3', 'Garden Town'],
        'Muslim Town' => ['R3', 'Kalma Chowk'],
        'DHA Phase 5' => null,
        'Cantt' => null,
        'Bahria Town' => null,
        'Valencia Town' => null,
        'Shadman' => null,
    ];

    /**
     * Towns hostel students come from.
     */
    public const HOME_TOWNS = ['Sahiwal', 'Okara', 'Kasur', 'Sheikhupura', 'Pakpattan', 'Bahawalnagar', 'Multan', 'Jhang'];

    public const BLOOD_GROUPS = ['O+' => 35, 'B+' => 30, 'A+' => 20, 'AB+' => 6, 'O-' => 4, 'B-' => 2, 'A-' => 2, 'AB-' => 1];

    public const PREVIOUS_SCHOOLS = [
        'City Grammar School, Lahore', 'Bright Future School, Lahore', 'The Learning Tree, Islamabad',
        'Al-Huda Model School, Faisalabad', 'Crescent Public School, Gujranwala', 'Pak Grammar School, Karachi',
    ];

    public const MEDICAL_NOTES = [
        'Mild asthma: keeps an inhaler in the school bag.',
        'Allergic to peanuts.',
        'Wears glasses; please seat near the board.',
        'Lactose intolerant.',
        'Recovering from a fractured wrist: excused from PE until further notice.',
    ];

    public static function address(string $area, Dice $dice): string
    {
        return __('House :house, Street :street, :area, Lahore', [
            'house' => $dice->int(1, 240).($dice->chance(20) ? '-'.$dice->pick(['A', 'B', 'C']) : ''),
            'street' => $dice->int(1, 28),
            'area' => $area,
        ]);
    }
}
