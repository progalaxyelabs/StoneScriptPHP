import { CommonModule } from '@angular/common';
import { Component } from '@angular/core';
import { RouterModule } from '@angular/router';

@Component({
    selector: 'app-menu1',
    imports: [CommonModule, RouterModule],
    templateUrl: './menu1.component.html',
    styleUrl: './menu1.component.css'
})
export class Menu1Component {

    categories = [
        {
            title: 'Category 1', subCategories: [
                { title: 'Sub Category 1', url: '/ecomm1/categories?category=1&subcategory=1' },
                { title: 'Sub Category 2', url: '/ecomm1/categories?category=1&subcategory=2' },
                { title: 'Sub Category 3', url: '/ecomm1/categories?category=1&subcategory=3' },
                { title: 'Sub Category 4', url: '/ecomm1/categories?category=1&subcategory=4' },
                { title: 'Sub Category 5', url: '/ecomm1/categories?category=1&subcategory=5' },
                { title: 'Sub Category 6', url: '/ecomm1/categories?category=1&subcategory=6' },
                { title: 'Sub Category 7', url: '/ecomm1/categories?category=1&subcategory=7' },
                { title: 'Sub Category 8', url: '/ecomm1/categories?category=1&subcategory=8' },
                { title: 'Sub Category 9', url: '/ecomm1/categories?category=1&subcategory=9' },
                { title: 'Sub Category 10', url: '/ecomm1/categories?category=1&subcategory=10' },
                { title: 'Sub Category 11', url: '/ecomm1/categories?category=1&subcategory=11' },
                { title: 'Sub Category 12', url: '/ecomm1/categories?category=1&subcategory=12' },
                { title: 'Sub Category 13', url: '/ecomm1/categories?category=1&subcategory=13' },
                { title: 'Sub Category 14', url: '/ecomm1/categories?category=1&subcategory=14' },
                { title: 'Sub Category 15', url: '/ecomm1/categories?category=1&subcategory=15' },
                { title: 'Sub Category 16', url: '/ecomm1/categories?category=1&subcategory=16' },
                { title: 'Sub Category 17', url: '/ecomm1/categories?category=1&subcategory=17' },
                { title: 'Sub Category 18', url: '/ecomm1/categories?category=1&subcategory=18' },
                { title: 'Sub Category 19', url: '/ecomm1/categories?category=1&subcategory=19' },
                { title: 'Sub Category 20', url: '/ecomm1/categories?category=1&subcategory=20' },
                { title: 'Sub Category 21', url: '/ecomm1/categories?category=1&subcategory=21' },
                { title: 'Sub Category 22', url: '/ecomm1/categories?category=1&subcategory=23' },
                { title: 'Sub Category 23', url: '/ecomm1/categories?category=1&subcategory=23' },
                { title: 'Sub Category 24', url: '/ecomm1/categories?category=1&subcategory=24' },
            ]
        },
        {
            title: 'Category 2', subCategories: [
                { title: 'Sub Category 1', url: '/ecomm1/categories?category=2&subcategory=1' },
                { title: 'Sub Category 2', url: '/ecomm1/categories?category=2&subcategory=2' },
                { title: 'Sub Category 3', url: '/ecomm1/categories?category=2&subcategory=3' },
                { title: 'Sub Category 4', url: '/ecomm1/categories?category=2&subcategory=4' },
                { title: 'Sub Category 5', url: '/ecomm1/categories?category=2&subcategory=5' },
                { title: 'Sub Category 6', url: '/ecomm1/categories?category=2&subcategory=6' },
                { title: 'Sub Category 7', url: '/ecomm1/categories?category=2&subcategory=7' },
                { title: 'Sub Category 8', url: '/ecomm1/categories?category=2&subcategory=8' },
                { title: 'Sub Category 9', url: '/ecomm1/categories?category=2&subcategory=9' },
                { title: 'Sub Category 10', url: '/ecomm1/categories?category=2&subcategory=10' },
                { title: 'Sub Category 11', url: '/ecomm1/categories?category=2&subcategory=11' },
                { title: 'Sub Category 12', url: '/ecomm1/categories?category=2&subcategory=12' },
            ]
        },
        {
            title: 'Category 3', subCategories: [
                { title: 'Sub Category 1', url: '/ecomm1/categories?category=3&subcategory=1' },
                { title: 'Sub Category 2', url: '/ecomm1/categories?category=3&subcategory=1' },
                { title: 'Sub Category 3', url: '/ecomm1/categories?category=3&subcategory=1' },
                { title: 'Sub Category 4', url: '/ecomm1/categories?category=3&subcategory=1' },
                { title: 'Sub Category 5', url: '/ecomm1/categories?category=3&subcategory=1' },
                { title: 'Sub Category 6', url: '/ecomm1/categories?category=3&subcategory=1' },
                { title: 'Sub Category 7', url: '/ecomm1/categories?category=3&subcategory=1' },
                { title: 'Sub Category 8', url: '/ecomm1/categories?category=3&subcategory=1' },
                { title: 'Sub Category 9', url: '/ecomm1/categories?category=3&subcategory=1' },
                { title: 'Sub Category 10', url: '/ecomm1/categories?category=3&subcategory=1' },
                { title: 'Sub Category 11', url: '/ecomm1/categories?category=3&subcategory=1' },
                { title: 'Sub Category 12', url: '/ecomm1/categories?category=3&subcategory=1' },
            ]
        },
        {
            title: 'Category 4', subCategories: [
                { title: 'Sub Category 1', url: '/ecomm1/categories?category=4&subcategory=1' },
                { title: 'Sub Category 2', url: '/ecomm1/categories?category=4&subcategory=2' },
                { title: 'Sub Category 3', url: '/ecomm1/categories?category=4&subcategory=3' },
                { title: 'Sub Category 4', url: '/ecomm1/categories?category=4&subcategory=4' },
                { title: 'Sub Category 5', url: '/ecomm1/categories?category=4&subcategory=5' },
                { title: 'Sub Category 6', url: '/ecomm1/categories?category=4&subcategory=6' },
                { title: 'Sub Category 7', url: '/ecomm1/categories?category=4&subcategory=7' },
                { title: 'Sub Category 8', url: '/ecomm1/categories?category=4&subcategory=8' },
                { title: 'Sub Category 9', url: '/ecomm1/categories?category=4&subcategory=9' },
                { title: 'Sub Category 10', url: '/ecomm1/categories?category=4&subcategory=10' },
                { title: 'Sub Category 11', url: '/ecomm1/categories?category=4&subcategory=11' },
                { title: 'Sub Category 12', url: '/ecomm1/categories?category=4&subcategory=12' },
            ]
        },
        {
            title: 'More', subCategories: [
                { title: 'Sub Category 1', url: '/ecomm1/categories?category=5&subcategory=1' },
                { title: 'Sub Category 2', url: '/ecomm1/categories?category=5&subcategory=2' },
                { title: 'Sub Category 3', url: '/ecomm1/categories?category=5&subcategory=3' },
                { title: 'Sub Category 4', url: '/ecomm1/categories?category=5&subcategory=4' },
                { title: 'Sub Category 5', url: '/ecomm1/categories?category=5&subcategory=5' },
                { title: 'Sub Category 6', url: '/ecomm1/categories?category=5&subcategory=6' },
                { title: 'Sub Category 7', url: '/ecomm1/categories?category=5&subcategory=7' },
                { title: 'Sub Category 8', url: '/ecomm1/categories?category=5&subcategory=8' },
                { title: 'Sub Category 9', url: '/ecomm1/categories?category=5&subcategory=9' },
                { title: 'Sub Category 10', url: '/ecomm1/categories?category=5&subcategory=10' },
                { title: 'Sub Category 11', url: '/ecomm1/categories?category=5&subcategory=11' },
                { title: 'Sub Category 12', url: '/ecomm1/categories?category=5&subcategory=12' },
            ]
        },
    ]

}
