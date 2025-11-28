import { CommonModule } from '@angular/common';
import { Component } from '@angular/core';
import { RouterModule } from '@angular/router';

@Component({
    selector: 'app-menu2',
    imports: [CommonModule, RouterModule],
    templateUrl: './menu2.component.html',
    styleUrl: './menu2.component.css'
})
export class Menu2Component {
    categories = [
        { title: 'Category 1', image: '/assets/ecomm1/100x100.jpeg', url: '/ecomm1/categories?category=1&subcategory=1' },
        { title: 'Category 2', image: '/assets/ecomm1/100x100.jpeg', url: '/ecomm1/categories?category=2&subcategory=1' },
        { title: 'Category 3', image: '/assets/ecomm1/100x100.jpeg', url: '/ecomm1/categories?category=3&subcategory=1' },
        { title: 'Category 4', image: '/assets/ecomm1/100x100.jpeg', url: '/ecomm1/categories?category=4&subcategory=1' },
        { title: 'Category 5', image: '/assets/ecomm1/100x100.jpeg', url: '/ecomm1/categories?category=5&subcategory=1' },
        { title: 'Category 6', image: '/assets/ecomm1/100x100.jpeg', url: '/ecomm1/categories?category=6&subcategory=1' },
        { title: 'Category 7', image: '/assets/ecomm1/100x100.jpeg', url: '/ecomm1/categories?category=7&subcategory=1' },
        { title: 'Category 8', image: '/assets/ecomm1/100x100.jpeg', url: '/ecomm1/categories?category=8&subcategory=1' },
    ]

}
