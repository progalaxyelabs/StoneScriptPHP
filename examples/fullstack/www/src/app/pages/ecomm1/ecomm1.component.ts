import { Component } from '@angular/core';
import { Banner1Component } from "./components/banner1/banner1.component";
import { Menu1Component } from "./components/menu1/menu1.component";
import { Menu2Component } from "./components/menu2/menu2.component";
import { Carousel1Component } from "./components/carousel1/carousel1.component";

@Component({
    selector: 'app-ecomm1',
    imports: [Banner1Component, Menu1Component, Menu2Component, Carousel1Component],
    templateUrl: './ecomm1.component.html',
    styleUrl: './ecomm1.component.css'
})
export class Ecomm1Component {

}
