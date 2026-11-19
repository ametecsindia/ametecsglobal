<?php

namespace App\Demo;

/**
 * 10-Nov-2026 (Ejaz): Explore Demo — the demo company is set in the city the visitor picks (same list as rovexy.app).
 * Each city carries its office location, a few real localities and the name pool its sample employees are drawn from.
 */
class DemoCities
{
    public const CITIES = [
        'ahmedabad' => ['Ahmedabad', 'Gujarat', 23.0300, 72.5800, ['Navrangpura', 'SG Highway', 'Prahlad Nagar', 'Satellite'], 'west'],
        'bengaluru' => ['Bengaluru', 'Karnataka', 12.9352, 77.6245, ['Koramangala', 'Whitefield', 'HSR Layout', 'Indiranagar'], 'kannada'],
        'bhubaneswar' => ['Bhubaneswar', 'Odisha', 20.2961, 85.8245, ['Saheed Nagar', 'Patia', 'Jaydev Vihar', 'Chandrasekharpur'], 'east'],
        'chandigarh' => ['Chandigarh', 'Chandigarh', 30.7333, 76.7794, ['Sector 17', 'Sector 35', 'IT Park', 'Sector 22'], 'north'],
        'chennai' => ['Chennai', 'Tamil Nadu', 13.0418, 80.2341, ['T. Nagar', 'Guindy', 'OMR Thoraipakkam', 'Anna Nagar'], 'tamil'],
        'coimbatore' => ['Coimbatore', 'Tamil Nadu', 11.0168, 76.9558, ['RS Puram', 'Peelamedu', 'Gandhipuram', 'Saravanampatti'], 'tamil'],
        'delhi' => ['Delhi', 'Delhi', 28.6315, 77.2167, ['Connaught Place', 'Nehru Place', 'Saket', 'Janakpuri'], 'north'],
        'hyderabad' => ['Hyderabad', 'Telangana', 17.4486, 78.3908, ['Kondapur', 'Madhapur', 'Gachibowli', 'Begumpet'], 'telugu'],
        'indore' => ['Indore', 'Madhya Pradesh', 22.7196, 75.8577, ['Vijay Nagar', 'Palasia', 'AB Road', 'Rajendra Nagar'], 'north'],
        'jaipur' => ['Jaipur', 'Rajasthan', 26.9124, 75.7873, ['C-Scheme', 'Malviya Nagar', 'Vaishali Nagar', 'Tonk Road'], 'north'],
        'kochi' => ['Kochi', 'Kerala', 9.9816, 76.2999, ['Kakkanad', 'Edappally', 'MG Road', 'Vyttila'], 'malayalam'],
        'kolkata' => ['Kolkata', 'West Bengal', 22.5726, 88.3639, ['Salt Lake Sector V', 'Park Street', 'New Town', 'Ballygunge'], 'east'],
        'lucknow' => ['Lucknow', 'Uttar Pradesh', 26.8467, 80.9462, ['Hazratganj', 'Gomti Nagar', 'Aliganj', 'Indira Nagar'], 'north'],
        'madurai' => ['Madurai', 'Tamil Nadu', 9.9252, 78.1198, ['Anna Nagar', 'KK Nagar', 'Tallakulam', 'Bypass Road'], 'tamil'],
        'mumbai' => ['Mumbai', 'Maharashtra', 19.0596, 72.8656, ['Bandra Kurla Complex', 'Andheri East', 'Lower Parel', 'Powai'], 'west'],
        'nagpur' => ['Nagpur', 'Maharashtra', 21.1458, 79.0882, ['Sitabuldi', 'Dharampeth', 'Civil Lines', 'Ramdaspeth'], 'west'],
        'patna' => ['Patna', 'Bihar', 25.5941, 85.1376, ['Boring Road', 'Fraser Road', 'Kankarbagh', 'Bailey Road'], 'north'],
        'pune' => ['Pune', 'Maharashtra', 18.5590, 73.7868, ['Hinjewadi', 'Baner', 'Kharadi', 'Shivajinagar'], 'west'],
        'salem' => ['Salem', 'Tamil Nadu', 11.6643, 78.1460, ['Fairlands', 'Hasthampatti', 'Five Roads', 'Alagapuram'], 'tamil'],
        'tiruchirappalli' => ['Tiruchirappalli', 'Tamil Nadu', 10.7905, 78.7047, ['Thillai Nagar', 'Cantonment', 'K.K. Nagar', 'Srirangam'], 'tamil'],
        'vijayawada' => ['Vijayawada', 'Andhra Pradesh', 16.5062, 80.6480, ['Benz Circle', 'Labbipet', 'Governorpet', 'Moghalrajpuram'], 'telugu'],
        'visakhapatnam' => ['Visakhapatnam', 'Andhra Pradesh', 17.7231, 83.3013, ['Dwaraka Nagar', 'MVP Colony', 'Siripuram', 'Rushikonda'], 'telugu'],
    ];

    /** [first names, last names] per region. ponytail: 10 employees per demo, so a short pool per region is plenty. */
    private const NAMES = [
        'telugu' => [['Ravi', 'Lakshmi', 'Srinivas', 'Swathi', 'Kiran', 'Divya', 'Venkatesh', 'Sravani', 'Naveen', 'Harika', 'Praveen', 'Anusha'],
            ['Reddy', 'Rao', 'Naidu', 'Chowdary', 'Varma', 'Goud', 'Kumar', 'Prasad']],
        'tamil' => [['Karthik', 'Priya', 'Arun', 'Divya', 'Senthil', 'Kavitha', 'Vignesh', 'Deepa', 'Saravanan', 'Lakshmi', 'Prakash', 'Meena'],
            ['Kumar', 'Raman', 'Subramanian', 'Murugan', 'Krishnan', 'Rajan', 'Selvam', 'Natarajan']],
        'kannada' => [['Manjunath', 'Shwetha', 'Prashanth', 'Kavya', 'Raghavendra', 'Pooja', 'Sandeep', 'Rashmi', 'Naveen', 'Deepika', 'Girish', 'Sowmya'],
            ['Gowda', 'Shetty', 'Rao', 'Hegde', 'Murthy', 'Kumar', 'Nayak', 'Bhat']],
        'malayalam' => [['Arjun', 'Anjali', 'Vishnu', 'Lakshmi', 'Rahul', 'Aswathy', 'Sreejith', 'Neethu', 'Akhil', 'Reshma', 'Jithin', 'Athira'],
            ['Nair', 'Menon', 'Pillai', 'Kurian', 'Varghese', 'Thomas', 'Nambiar', 'Joseph']],
        'west' => [['Rohan', 'Sneha', 'Amit', 'Pooja', 'Nikhil', 'Priyanka', 'Sagar', 'Neha', 'Tushar', 'Komal', 'Hardik', 'Riya'],
            ['Patil', 'Shah', 'Desai', 'Joshi', 'Kulkarni', 'Patel', 'Deshmukh', 'Mehta']],
        'north' => [['Rahul', 'Priya', 'Amit', 'Neha', 'Vikas', 'Anjali', 'Rohit', 'Pooja', 'Saurabh', 'Shivani', 'Manish', 'Kavita'],
            ['Sharma', 'Verma', 'Singh', 'Gupta', 'Yadav', 'Mishra', 'Agarwal', 'Chauhan']],
        'east' => [['Subhajit', 'Priyanka', 'Sourav', 'Rimpa', 'Arindam', 'Moumita', 'Debasish', 'Sanchita', 'Biswajit', 'Ananya', 'Satyajit', 'Rupali'],
            ['Das', 'Banerjee', 'Mohanty', 'Chatterjee', 'Sahoo', 'Ghosh', 'Mishra', 'Sen']],
    ];

    public static function get(string $key): array
    {
        $c = self::CITIES[$key] ?? self::CITIES['hyderabad'];

        return ['key' => array_key_exists($key, self::CITIES) ? $key : 'hyderabad', 'name' => $c[0], 'state' => $c[1], 'lat' => $c[2], 'lng' => $c[3],
            'areas' => $c[4], 'names' => self::NAMES[$c[5]]];
    }
}
