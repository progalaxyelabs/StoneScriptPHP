import { Component, OnInit } from '@angular/core';
import { WebsiteSection, WebsiteService } from '../../website.service';
import { FormsModule } from '@angular/forms';
import { NgIf } from '@angular/common';

@Component({
    selector: 'app-change-background',
    templateUrl: './change-background.component.html',
    styleUrls: ['./change-background.component.css'],
    imports: [FormsModule, NgIf]
})
export class ChangeBackgroundComponent implements OnInit {
    selectedBackgroundType = 'none'

    backgroundSingleColorValue = '#000'
    backgroundColorGradient1Value = '#ffff00'
    backgroundColorGradient2Value = '#ffa70f'

    colorgradientStyle = 'right'
    gradientBackground1 = 'linear-gradient(to right, yellow, orange)'
    gradientBackground2 = 'linear-gradient(to bottom, yellow, orange)'
    gradientBackground3 = 'linear-gradient(to right bottom, yellow, orange)'

    backgroundType = 'none'

    constructor(
        private websiteService: WebsiteService
    ) { }

    ngOnInit(): void {
        
    }

    onBackgroundColorGradientValueChange(event: Event) {
        this.gradientBackground1 = `linear-gradient(to right, ${this.backgroundColorGradient1Value}, ${this.backgroundColorGradient2Value})`
        this.gradientBackground2 = `linear-gradient(to bottom, ${this.backgroundColorGradient1Value}, ${this.backgroundColorGradient2Value})`
        this.gradientBackground3 = `linear-gradient(to right bottom, ${this.backgroundColorGradient1Value}, ${this.backgroundColorGradient2Value})`
    }

    onApplyBackgroundClick(event: Event) {
        let background = 'none'
        let image = '/assets/dummy-image.png'

        switch (this.selectedBackgroundType) {
            case 'singlecolor':
                background = this.backgroundSingleColorValue
                break
            case 'colorgradient':
                background = `linear-gradient(to ${this.colorgradientStyle}, ${this.backgroundColorGradient1Value}, ${this.backgroundColorGradient2Value})`
                break
            case 'image':
                background = `url(${image})`
                break
            default:
                background = 'none'

        }
        this.websiteService.activeWebsite?.onActiveSectionBackgroundChange.next(background)
    }

    onBackgroundTypeChange(value: string) {
        this.selectedBackgroundType = value
    }


}
